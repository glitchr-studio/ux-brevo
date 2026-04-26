<?php

namespace Brevo\Bundle\Subscriber;

use \Symfony\Component\HttpKernel\Event\RequestEvent;

use Twig\Environment;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class BrevoListener
{
    /** @var bool */
    protected ?bool $enable;
    /** @var bool */
    protected ?bool $autoAppend;
    /** @var string */
    protected ?string $websiteId;

    /**
     * @var Environment
     */
    protected $twig;

    /**
     * @var ParameterBagInterface
     */
    protected $parameterBag;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    public function __construct(ParameterBagInterface $parameterBag, Environment $twig, RequestStack $requestStack)
    {
        $this->twig         = $twig;
        $this->requestStack = $requestStack;

        $this->enable = $parameterBag->get("brevo.enable");
        $this->autoAppend = $parameterBag->get("brevo.autoappend");
        $this->websiteId = $parameterBag->get("brevo.id");
    }

    private function allowRender(ResponseEvent $event): bool
    {
        if (!$this->websiteId) return false;
        if (!$this->autoAppend)
            return false;
        
            $contentType = $event->getResponse()->headers->get('content-type');
        if ($contentType && !str_contains($contentType, "text/html"))
            return false;
    
        if (!$event->isMainRequest())
            return false;

        if ($this->isEasyAdmin($event))
            return false;
    
        return !$this->isProfiler($event) && $event->getResponse()->getStatusCode() == 200;
    }

    public function isProfiler($event)
    {
        $route = $event->getRequest()->attributes->get('_route');
        return str_starts_with($route ?? "", "_wdt") || str_starts_with($route ?? "", "_profiler");
    }

    public function isEasyAdmin($event)
    {
        $controllerAttribute = $event->getRequest()->attributes->get("_controller");
        if($controllerAttribute === null) return false;
        
        $array = is_array($controllerAttribute) ? $controllerAttribute : explode("::", $controllerAttribute);
        $controller = explode("::", $array[0])[0];

        $parents = [];
        $parent = $controller;
        while(class_exists($parent) && ( $parent = get_parent_class($parent)))
            $parents[] = $parent;

        $eaParents = array_filter($parents, fn($c) => str_starts_with($c, "EasyCorp\Bundle\EasyAdminBundle"));
        return !empty($eaParents);
    }

    public function getAsset(string $url): string
    {
        $url = trim($url);
        $parseUrl = parse_url($url);
        if($parseUrl["scheme"] ?? false)
            return $url;

        $request = $this->requestStack->getCurrentRequest();
        $baseDir = $request ? $request->getBasePath() : $_SERVER["CONTEXT_PREFIX"] ?? "";

        $path = trim($parseUrl["path"]);
        if($path == "/") return $baseDir;
        else if(!str_starts_with($path, "/"))
            $path = $baseDir."/".$path;

        return $path;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        if (!$this->enable) return;
        if (!$this->websiteId) return;

        if (!$event->isMainRequest()) return false;

        if ($this->isProfiler ($event)) return false;
        if ($this->isEasyAdmin($event)) return false;

        $locale = substr($event->getRequest()->getLocale(),0,2);
        $javascript = "<!-- Brevo Conversations {literal} -->".
			"<script>".
			"    (function(d, w, c) {".
			"        w.BrevoConversationsID = '".$this->websiteId."';".
			"        w[c] = w[c] || function() {".
			"            (w[c].q = w[c].q || []).push(arguments);".
			"        };".
			"        var s = d.createElement('script');".
			"        s.async = true;".
			"        s.src = 'https://conversations-widget.brevo.com/brevo-conversations.js';".
			"        if (d.head) d.head.appendChild(s);".
			"    })(document, window, 'BrevoConversations');".
			"</script>".
			"<!-- /Brevo Conversations {/literal} -->";

        $this->twig->addGlobal("brevo", $this->twig->getGlobals()["brevo"] ?? "" . $javascript);
    }

    public function onKernelResponse(ResponseEvent $event)
    {
        if (!$this->allowRender($event)) return false;

        $response = $event->getResponse();
        $javascript = $this->twig->getGlobals()["brevo"] ?? "";

        $content = preg_replace(['/<\/head\b[^>]*>/'], [$javascript."$0"], $response->getContent(), 1);
        $response->setContent($content);

        return true;
    }
}
