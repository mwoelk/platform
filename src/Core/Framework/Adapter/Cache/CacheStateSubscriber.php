<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Adapter\Cache;

use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @package core
 *
 * @deprecated tag:v6.5.0 - reason:becomes-internal - EventSubscribers will become internal in v6.5.0
 */
class CacheStateSubscriber implements EventSubscriberInterface
{
    public const STATE_LOGGED_IN = 'logged-in';

    public const STATE_CART_FILLED = 'cart-filled';

    private CartService $cartService;

    /**
     * @internal
     */
    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * @return array<string, string|array{0: string, 1: int}|list<array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => [
                ['setStates', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST],
            ],
            CustomerLoginEvent::class => 'login',
            CartChangedEvent::class => 'cartChanged',
        ];
    }

    public function login(CustomerLoginEvent $event): void
    {
        $event->getSalesChannelContext()->addState(self::STATE_LOGGED_IN);
    }

    public function cartChanged(CartChangedEvent $event): void
    {
        $event->getContext()->removeState(self::STATE_CART_FILLED);

        if ($event->getCart()->getLineItems()->count() > 0) {
            $event->getContext()->addState(self::STATE_CART_FILLED);
        }
    }

    public function setStates(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->attributes->has(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT)) {
            return;
        }

        /** @var SalesChannelContext $context */
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $context->removeState(self::STATE_LOGGED_IN);

        $context->removeState(self::STATE_CART_FILLED);

        if ($cart->getLineItems()->count() > 0) {
            $context->addState(self::STATE_CART_FILLED);
        }

        if ($context->getCustomer() !== null) {
            $context->addState(self::STATE_LOGGED_IN);
        }
    }
}
