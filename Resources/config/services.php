<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Brevo\Bundle\Twig\BrevoTwigExtension;
use Brevo\Bundle\Subscriber\BrevoListener;

return static function (ContainerConfigurator $container) {
    $services = $container->services();

    $services->defaults()
        ->public(false);

    $services
        ->set('brevo.twig_extension', BrevoTwigExtension::class)
        ->tag('twig.extension');

    $services
        ->set('brevo.listener', BrevoListener::class)
        ->tag('kernel.event_listener', [
            'event'  => 'kernel.request',
            'method' => 'onKernelRequest',
        ])
        ->tag('kernel.event_listener', [
            'event'  => 'kernel.response',
            'method' => 'onKernelResponse',
        ])
        ->args([
            service('parameter_bag'),
            service('twig'),
            service('request_stack'),
        ]);
};