<?php

require_once dirname(__DIR__).'/autoload.php';

class GuardlmsPlugin extends Plugin
{
    protected function __construct()
    {
        parent::__construct('0.1.0', 'Ldesign Media', []);
    }
    public static function create(): self
    {
        static $instance;
        return $instance ?: $instance = new self();
    }
    public function get_info(): array
    {
        // Inventory collection must not instantiate an administrator settings form in CLI.
        return [
            'obj' => $this, 'title' => 'GuardLMS', 'comment' => 'Inventory, security checks and optional monitoring.',
            'version' => '0.1.0', 'author' => 'Ldesign Media', 'plugin_class' => static::class,
            'supports_regions' => true, 'is_course_plugin' => false, 'is_admin_plugin' => false, 'is_mail_plugin' => false,
        ];
    }
    public function get_name(): string
    {
        return 'guardlms';
    }
    public function install(): void
    {
    }
    public function uninstall(): void
    {
        (new \GuardLMS\Chamilo\Connector())->disconnect();
    }
    public function renderRegion($region): string
    {
        if (!in_array($region, ['header_main', 'pre_footer'], true)) {
            return '';
        }
        try {
            return (new \GuardLMS\Chamilo\Connector())->markup($region === 'header_main');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
