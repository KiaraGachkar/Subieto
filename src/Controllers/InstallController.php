<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Installer;
use App\Core\Session;
use App\Core\View;

defined('APP_SECURE_BOOT') or exit;

final class InstallController
{
    private string $basePath;
    private Installer $installer;
    private View $view;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(array $context)
    {
        $this->basePath  = $context['basePath'];
        $this->installer = $context['installer'];
        $this->view      = new View($this->basePath . '/views');
    }

    public function index(): void
    {
        $checks = $this->installer->checkEnvironment();
        $allPassed = true;
        foreach ($checks as $c) {
            if (!$c['ok']) {
                $allPassed = false;
                break;
            }
        }

        $this->view->render('install/wizard', [
            'checks'    => $checks,
            'allPassed' => $allPassed,
            'error'     => Session::flash('install_error'),
            'old'       => $_SESSION['install_old'] ?? [],
        ], null);
        unset($_SESSION['install_old']);
    }

    public function run(): void
    {
        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('install_error', 'توکن امنیتی نامعتبر است. صفحه را رفرش کنید.');
            header('Location: /install', true, 302);
            exit;
        }

        $_SESSION['install_old'] = array_filter(array_diff_key($_POST, ['db_pass' => 1, 'admin_password' => 1, '_csrf' => 1]), 'is_string');

        $result = $this->installer->install($_POST);
        if (!$result['success']) {
            Session::flash('install_error', $result['message']);
            header('Location: /install', true, 302);
            exit;
        }

        unset($_SESSION['install_old']);
        Session::setAdmin(true);
        Session::flash('success', 'نصب خودکار دیتابیس و تنظیمات امنیتی با موفقیت به پایان رسید!');
        header('Location: /admin', true, 302);
        exit;
    }
}
