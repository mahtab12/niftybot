<?php

namespace Drupal\user_dashboard\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Routing\CurrentRouteMatch;

/**
 * Redirects user to dashboard after login.
 *
 * Implementation detail:
 * After a successful login Drupal typically lands users on the 'user.page' route (/user).
 * We intercept requests to that route and redirect to /user/dashboard.
 */
class LoginRedirectSubscriber implements EventSubscriberInterface {

  protected AccountProxyInterface $currentUser;
  protected CurrentRouteMatch $currentRouteMatch;

  public function __construct(AccountProxyInterface $current_user, CurrentRouteMatch $current_route_match) {
    $this->currentUser = $current_user;
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    $events[KernelEvents::REQUEST][] = ['redirectUserDashboard', 35];
    return $events;
  }

  /**
   * Redirect authenticated users who hit /user to /user/dashboard.
   */
  public function redirectUserDashboard(RequestEvent $event) : void {
    if ($event->isMainRequest() === FALSE) {
      return;
    }

    $route_name = $this->currentRouteMatch->getRouteName();
    if ($route_name === 'user.page' && $this->currentUser->isAuthenticated()) {
      $response = new RedirectResponse('/user/dashboard');
      $event->setResponse($response);
    }
  }

}
