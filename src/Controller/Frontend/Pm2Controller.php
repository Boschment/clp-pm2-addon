<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Service\Logger;

class Pm2Controller extends Controller
{
    private const DATA_DIR = '/opt/clp-pm2-addon/data';
    private const HELPER   = '/usr/local/bin/clp-pm2-helper';

    private SiteManager $siteEntityManager;

    public function __construct(
        TranslatorInterface $translator,
        Logger $logger,
        SiteManager $siteEntityManager
    ) {
        parent::__construct($translator, $logger);
        $this->siteEntityManager = $siteEntityManager;
    }

    public function index(Request $request, string $domainName): Response
    {
        $ctx = $this->resolveContextOrRedirect($domainName);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$site, $siteUser, $siteRoot, $runtimeUser] = $ctx;

        $procs       = $this->fetchStatus($domainName, $runtimeUser, $siteRoot);
        $isRunning   = $this->anyOnline($procs);
        $config      = $this->loadConfig($domainName) ?? $this->defaultConfig($siteUser, $domainName, $siteRoot);
        $allowedUsers = $this->listAllowedUsers($siteRoot);

        return $this->render('Frontend/Site/pm2.html.twig', [
            'site'         => $site,
            'user'         => $this->getUser(),
            'formErrors'   => [],
            'config'       => $config,
            'configSaved'  => null !== $this->loadConfig($domainName),
            'procs'        => $procs,
            'isRunning'    => $isRunning,
            'siteUser'     => $siteUser,
            'runtimeUser'  => $runtimeUser,
            'allowedUsers' => $allowedUsers,
        ]);
    }

    public function saveUser(Request $request, string $domainName): Response
    {
        $ctx = $this->resolveContextOrRedirect($domainName);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [, $siteUser, $siteRoot] = $ctx;

        $this->checkCsrfToken($request, 'pm2-save-user');

        $newUser = trim((string) $request->request->get('runtime_user', ''));
        $allowed = array_column($this->listAllowedUsers($siteRoot), 'name');

        if ('' === $newUser || !in_array($newUser, $allowed, true)) {
            $this->addFlash('error', 'Invalid PM2 user. Pick a user that has access to the site directory.');
            return $this->redirect($this->generateUrl('clp_site_pm2', ['domainName' => $domainName]));
        }

        $this->saveRuntimeUser($domainName, $newUser);

        // Make sure pm2 + the per-user systemd startup unit exist for the new user.
        $this->callHelper('ensure-pm2', [$newUser]);

        if ($newUser === $siteUser) {
            $this->addFlash('success', 'PM2 user reset to site owner (' . $newUser . ').');
        } else {
            $this->addFlash('success', 'PM2 will now run as ' . $newUser . '. Stop & start the app for it to take effect.');
        }

        return $this->redirect($this->generateUrl('clp_site_pm2', ['domainName' => $domainName]));
    }

    public function saveConfig(Request $request, string $domainName): Response
    {
        $ctx = $this->resolveContextOrRedirect($domainName);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [, , , $runtimeUser] = $ctx;
        $siteUser = $runtimeUser;

        $this->checkCsrfToken($request, 'pm2-save-config');

        $content = (string) $request->request->get('config', '');
        if ('' === trim($content)) {
            $this->addFlash('error', 'Ecosystem config cannot be empty.');
            return $this->redirect($this->generateUrl('clp_site_pm2', ['domainName' => $domainName]));
        }

        $this->saveConfigFile($domainName, $siteUser, $content);
        $this->addFlash('success', 'Ecosystem config saved.');

        return $this->redirect($this->generateUrl('clp_site_pm2', ['domainName' => $domainName]));
    }

    public function start(Request $request, string $domainName): Response
    {
        return $this->action($request, $domainName, 'start', 'pm2-start', 'PM2 app started.');
    }

    public function stop(Request $request, string $domainName): Response
    {
        return $this->action($request, $domainName, 'stop', 'pm2-stop', 'PM2 app stopped.');
    }

    public function restart(Request $request, string $domainName): Response
    {
        return $this->action($request, $domainName, 'restart', 'pm2-restart', 'PM2 app restarted.');
    }

    public function status(Request $request, string $domainName): JsonResponse
    {
        $ctx = $this->resolveContextOrRedirect($domainName);
        if ($ctx instanceof Response) {
            return new JsonResponse(['error' => 'not a node site'], 404);
        }
        [, , $siteRoot, $runtimeUser] = $ctx;

        $procs = $this->fetchStatus($domainName, $runtimeUser, $siteRoot);
        return new JsonResponse([
            'isRunning' => $this->anyOnline($procs),
            'procs'     => $procs,
        ]);
    }

    private function action(Request $request, string $domainName, string $cmd, string $token, string $okMsg): Response
    {
        $ctx = $this->resolveContextOrRedirect($domainName);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [, , $siteRoot, $runtimeUser] = $ctx;

        $this->checkCsrfToken($request, $token);

        $shell = sprintf(
            'sudo %s %s %s %s %s 2>&1',
            escapeshellarg(self::HELPER),
            escapeshellarg($cmd),
            escapeshellarg($domainName),
            escapeshellarg($runtimeUser),
            escapeshellarg($siteRoot)
        );
        exec($shell, $out, $rc);

        if (0 === $rc) {
            $this->addFlash('success', $okMsg);
        } else {
            $this->addFlash('error', sprintf('PM2 %s failed (exit %d): %s', $cmd, $rc, implode(' ', $out)));
        }

        return $this->redirect($this->generateUrl('clp_site_pm2', ['domainName' => $domainName]));
    }

    /**
     * @return array{0:object,1:string,2:string,3:string}|Response
     *   [site, siteUser (owner of htdocs), siteRoot, runtimeUser (saved override or siteUser)]
     */
    private function resolveContextOrRedirect(string $domainName)
    {
        $site = $this->siteEntityManager->findOneByDomainName($domainName);
        if (null === $site) {
            $this->addFlash('error', '[PM2] Site not found: ' . $domainName);
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $domainName)) {
            $this->addFlash('error', '[PM2] Invalid domain name: ' . $domainName);
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $matches = glob('/home/*/htdocs/' . $domainName);
        if (!$matches || !is_dir($matches[0])) {
            $this->addFlash('error', '[PM2] Site directory not found at /home/*/htdocs/' . $domainName);
            return $this->redirect($this->generateUrl('clp_sites'));
        }
        $siteRoot = $matches[0];
        $siteUser = trim((string) shell_exec('stat -c %U ' . escapeshellarg($siteRoot)));
        if ('' === $siteUser) {
            $this->addFlash('error', '[PM2] Could not determine site owner for: ' . $siteRoot);
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        if (!$this->isNodeSite($site, $domainName, $siteUser)) {
            $this->addFlash('error', 'PM2 tab is only available for Node.js sites.');
            return $this->redirect($this->generateUrl('clp_sites'));
        }

        $runtimeUser = $this->loadRuntimeUser($domainName);
        if (null === $runtimeUser) {
            $runtimeUser = $siteUser;
        } else {
            // If the saved user has since lost access to the site dir, fall back.
            $allowed = array_column($this->listAllowedUsers($siteRoot), 'name');
            if (!in_array($runtimeUser, $allowed, true)) {
                $runtimeUser = $siteUser;
            }
        }

        return [$site, $siteUser, $siteRoot, $runtimeUser];
    }

    private function runtimeUserPath(string $domainName): string
    {
        return self::DATA_DIR . '/' . $domainName . '/runtime-user';
    }

    private function loadRuntimeUser(string $domainName): ?string
    {
        $f = $this->runtimeUserPath($domainName);
        if (!is_file($f)) {
            return null;
        }
        $v = trim((string) file_get_contents($f));
        return ('' === $v || !preg_match('/^[a-z_][a-z0-9_-]*$/', $v)) ? null : $v;
    }

    private function saveRuntimeUser(string $domainName, string $user): void
    {
        $dir = self::DATA_DIR . '/' . $domainName;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        file_put_contents($this->runtimeUserPath($domainName), $user . "\n");
    }

    private function listAllowedUsers(string $siteRoot): array
    {
        $out = $this->callHelper('list-users', [$siteRoot]);
        $decoded = json_decode($out, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function callHelper(string $action, array $args): string
    {
        $cmd = 'sudo ' . escapeshellarg(self::HELPER) . ' ' . escapeshellarg($action);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string) $a);
        }
        return (string) shell_exec($cmd . ' 2>/dev/null');
    }

    private function isNodeSite($site, string $domainName, string $siteUser): bool
    {
        foreach (['getType', 'getSiteType', 'getApplication'] as $m) {
            if (method_exists($site, $m)) {
                $v = strtolower((string) $site->$m());
                if (in_array($v, ['nodejs', 'node', 'node.js', 'nodejssite'], true)) {
                    return true;
                }
                if (in_array($v, ['php', 'static', 'reverse-proxy', 'reverseproxy', 'python'], true)) {
                    return false;
                }
            }
        }
        // Fallback: no PHP-FPM pool + nginx vhost has proxy_pass
        if (glob('/etc/php/*/fpm/pool.d/' . $siteUser . '.conf')) {
            return false;
        }
        $vhost = '/etc/nginx/sites-enabled/' . $domainName . '.conf';
        if (!is_file($vhost)) {
            return false;
        }
        $content = (string) shell_exec('sudo cat ' . escapeshellarg($vhost) . ' 2>/dev/null');
        return false !== strpos($content, 'proxy_pass');
    }

    private function configPath(string $domainName): string
    {
        return self::DATA_DIR . '/' . $domainName . '/ecosystem.config.js';
    }

    private function loadConfig(string $domainName): ?string
    {
        $path = $this->configPath($domainName);
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if (false === $content) {
            // Owned by site user, mode 600 — read via sudo through helper-owned tee/cat.
            $content = (string) shell_exec('sudo cat ' . escapeshellarg($path) . ' 2>/dev/null');
        }
        return false === $content ? null : $content;
    }

    private function saveConfigFile(string $domainName, string $siteUser, string $content): void
    {
        $dir  = self::DATA_DIR . '/' . $domainName;
        $path = $dir . '/ecosystem.config.js';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
            @chown($dir, $siteUser);
            @chgrp($dir, $siteUser);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'clppm2_');
        file_put_contents($tmp, $content);
        // install(1) is in the env-addon's sudoers grant; the pm2 addon uses
        // its helper for everything privileged. Write the file directly here
        // since the data dir is owned by clp.
        @rename($tmp, $path);
        @chown($path, $siteUser);
        @chgrp($path, $siteUser);
        @chmod($path, 0600);
    }

    private function defaultConfig(string $siteUser, string $domainName, string $siteRoot): string
    {
        return <<<JS
module.exports = {
  apps: [{
    name: "{$domainName}",
    script: "./app.js",
    cwd: "{$siteRoot}",
    instances: 1,
    autorestart: true,
    watch: false,
    max_memory_restart: "512M",
    env: {
      NODE_ENV: "production",
      PORT: 3000
    }
  }]
};
JS;
    }

    private function fetchStatus(string $domainName, string $siteUser, string $siteRoot): array
    {
        $cmd = sprintf(
            'sudo %s status %s %s %s 2>/dev/null',
            escapeshellarg(self::HELPER),
            escapeshellarg($domainName),
            escapeshellarg($siteUser),
            escapeshellarg($siteRoot)
        );
        $out = (string) shell_exec($cmd);
        $decoded = json_decode($out, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function anyOnline(array $procs): bool
    {
        foreach ($procs as $p) {
            if (($p['status'] ?? '') === 'online') {
                return true;
            }
        }
        return false;
    }
}
