<?php

require_once dirname(__DIR__).'/autoload.php';

/** Chamilo 3 auto-discovers plugin event subscribers at container compilation. */
class GuardlmsEventSubscriber implements \Symfony\Component\EventDispatcher\EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['request', -128], 'kernel.exception' => ['exception', 128], 'kernel.response' => ['response', -128]];
    }
    private function connector()
    {
        if (!function_exists('api_get_path')) {
            return null;
        }
        $helper = \Chamilo\CoreBundle\Framework\Container::getPluginHelper();
        if (!$helper->isPluginEnabled('guardlms')) {
            return null;
        }
        return new \GuardLMS\Chamilo\Connector();
    }
    public function request($event): void
    {
        try {
            $connector = $this->connector();
            if ($connector) {
                \GuardLMS\Chamilo\Monitor::register($connector);
            }
        } catch (\Throwable $e) {
        }
    }
    public function exception($event): void
    {
        try {
            $connector = $this->connector();
            if ($connector) {
                \GuardLMS\Chamilo\Monitor::exception($connector, $event->getThrowable());
            }
        } catch (\Throwable $e) {
        }
    }
    public function response($event): void
    {
        // Verification must be in the actual document head, including the anonymous landing page.
        try {
            if (!$event->isMainRequest()) {
                return;
            }
            $response = $event->getResponse();
            if (strpos((string) $response->headers->get('Content-Type'), 'text/html') === false || !$response instanceof \Symfony\Component\HttpFoundation\Response || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                return;
            }
            $connector = $this->connector();
            if (!$connector) {
                return;
            }
            $content = $response->getContent();
            if (is_string($content) && stripos($content, '</head>') !== false) {
                $markup = $connector->markup(true);
                $response->setContent(preg_replace_callback('~</head>~i', static function () use ($markup) {
                    return $markup.'</head>';
                }, $content, 1));
                $response->headers->remove('Content-Length');
            }
        } catch (\Throwable $e) {
        }
    }
}
