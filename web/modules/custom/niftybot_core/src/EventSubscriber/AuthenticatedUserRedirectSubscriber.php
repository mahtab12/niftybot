<?php

declare(strict_types=1);

namespace Drupal\niftybot_core\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects authenticated users away from guest pages to the dashboard.
 */
class AuthenticatedUserRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Guest routes that authenticated users should not see.
   */
  private const GUEST_ROUTES = [
    'user.login',
    'user.register',
    'niftybot_user.register',
    'niftybot_core.landing',
    '<front>',
  ];

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  /**
   * Sends logged-in users to the trading dashboard.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->currentUser->isAnonymous()) {
      return;
    }

    $route = $this->routeMatch->getRouteName();
    if ($route === NULL || !in_array($route, self::GUEST_ROUTES, TRUE)) {
      return;
    }

    $dashboard = Url::fromRoute('niftybot_dashboard.main');
    if (!$dashboard->access($this->currentUser)) {
      return;
    }

    $event->setResponse(new RedirectResponse($dashboard->toString()));
  }

}
