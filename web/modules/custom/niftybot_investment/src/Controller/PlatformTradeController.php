<?php

namespace Drupal\niftybot_investment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\niftybot_investment\Service\InvestmentService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Platform Nifty trade transparency pages.
 */
class PlatformTradeController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected InvestmentService $investmentService,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('niftybot_investment.investment_service'),
      $container->get('database'),
    );
  }

  /**
   * User-facing daily Nifty trades with P&L.
   */
  public function userView() {
    return [
      '#theme' => 'niftybot_platform_trades',
      '#grouped_trades' => $this->investmentService->getPlatformTradesGrouped(),
      '#daily_summary' => $this->investmentService->getDailyPnlSummary(),
      '#attached' => ['library' => ['niftybot_investment/investment']],
    ];
  }

  /**
   * Admin list of platform trades.
   */
  public function adminList() {
    $trades = $this->database->select('niftybot_platform_trades', 't')
      ->fields('t')
      ->orderBy('trade_date', 'DESC')
      ->orderBy('trade_id', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll();

    $add_link = Link::fromTextAndUrl(
      $this->t('Add trade'),
      Url::fromRoute('niftybot_investment.admin_trade_add'),
    )->toRenderable();
    $add_link['#attributes']['class'][] = 'button';
    $add_link['#attributes']['class'][] = 'button--primary';

    return [
      '#theme' => 'niftybot_platform_trades_admin',
      '#trades' => $trades,
      '#add_link' => $add_link,
      '#attached' => ['library' => ['niftybot_investment/investment']],
    ];
  }

}
