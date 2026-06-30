<?php

declare(strict_types=1);

namespace Drupal\niftybot_core\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Shared FAQ content for public and authenticated pages.
 */
class FaqContentService {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * FAQ content grouped by section.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getSections(bool $public = FALSE): array {
    $sections = [
      [
        'id' => 'platform',
        'title' => $this->t('GrassRed platform'),
        'items' => [
          [
            'question' => $this->t('What is GrassRed?'),
            'answer' => $this->t('GrassRed is an automated algorithmic trading portal for Indian index options. Our AI-driven engine executes Nifty and Sensex strategies with institutional-style signal layers, portfolio tracking, and full trade transparency.'),
          ],
          [
            'question' => $this->t('Who is GrassRed built for?'),
            'answer' => $this->t('GrassRed is designed for active F&amp;O traders who want disciplined, rules-based entries instead of emotional trading. Whether you self-trade with auto-trade signals or invest in managed algo strategies, GrassRed gives you one portal to monitor everything.'),
          ],
        ],
      ],
      [
        'id' => 'account',
        'title' => $this->t('Account & KYC'),
        'items' => [
          [
            'question' => $this->t('Why is KYC required?'),
            'answer' => $this->t('KYC verification is required before you can trade or withdraw funds. It helps us comply with regulations and keep your account secure.'),
          ],
          [
            'question' => $this->t('How long does KYC review take?'),
            'answer' => $this->t('Most applications are reviewed within 1–2 business days. You will see the status on your dashboard and KYC page once a decision is made.'),
          ],
          [
            'question' => $this->t('Can I update my profile after registration?'),
            'answer' => $this->t('Yes. Go to My Profile from the sidebar to update your contact details. Identity documents may require a new KYC submission if details change.'),
          ],
        ],
      ],
      [
        'id' => 'wallet',
        'title' => $this->t('Wallet & payments'),
        'items' => [
          [
            'question' => $this->t('How do I add money to my wallet?'),
            'answer' => $this->t('Open Wallet from the sidebar, then choose Deposit. Follow the instructions and upload proof of payment if required. Deposits are credited after admin verification.'),
          ],
          [
            'question' => $this->t('When can I withdraw funds?'),
            'answer' => $this->t('Withdrawals are available once KYC is approved and you have sufficient available balance. Pending withdrawal requests reduce your available balance until processed.'),
          ],
          [
            'question' => $this->t('Why is my deposit still pending?'),
            'answer' => $this->t('Deposits are reviewed manually for security. Processing typically completes within one business day. Contact support if your deposit has been pending longer than expected.'),
          ],
        ],
      ],
      [
        'id' => 'trading',
        'title' => $this->t('Algo trading'),
        'items' => [
          [
            'question' => $this->t('How does automated algo trading work on GrassRed?'),
            'answer' => $this->t('GrassRed evaluates futures OI, EMA trend, RSI momentum, VWAP, volume, and option-chain data before placing trades. When confidence thresholds are met, the engine can enter and manage positions with automated stop-loss and target rules.'),
          ],
          [
            'question' => $this->t('How do I place a manual order?'),
            'answer' => $this->t('Use Place order from the dashboard or trading menu. Ensure you have an active subscription and approved KYC before placing live orders.'),
          ],
          [
            'question' => $this->t('Where can I see open positions and P&amp;L?'),
            'answer' => $this->t('Open Positions and Trade History are available from the sidebar. Your dashboard also shows a summary of open positions and recent orders.'),
          ],
          [
            'question' => $this->t('What happens if an order is rejected?'),
            'answer' => $this->t('Rejected orders do not affect your wallet balance. Check the order status and message in Orders or Trade History, then retry or contact support if the issue persists.'),
          ],
        ],
      ],
      [
        'id' => 'subscription',
        'title' => $this->t('Subscriptions & plans'),
        'items' => [
          [
            'question' => $this->t('Do I need a subscription to trade?'),
            'answer' => $this->t('An active subscription plan is required for full platform access. View available plans on the Pricing page or under Plans in your dashboard.'),
          ],
          [
            'question' => $this->t('How do I renew or change my plan?'),
            'answer' => $this->t('Visit Plans to compare options and subscribe. Your current plan and expiry date are shown on the dashboard when you have an active subscription.'),
          ],
        ],
      ],
      [
        'id' => 'investment',
        'title' => $this->t('AI investment'),
        'items' => [
          [
            'question' => $this->t('What is GrassRed AI investment?'),
            'answer' => $this->t('GrassRed AI investment lets you allocate capital to managed algo strategies on the platform. Returns and terms depend on the plan you select.'),
          ],
          [
            'question' => $this->t('How is profit calculated and paid?'),
            'answer' => $this->t('Profit accrues according to your investment plan. Pending and total earned amounts are shown on your dashboard and investment pages.'),
          ],
        ],
      ],
    ];

    if ($public) {
      return array_values(array_filter(
        $sections,
        static fn (array $section): bool => in_array($section['id'], ['platform', 'trading', 'subscription', 'investment'], TRUE)
      ));
    }

    return $sections;
  }

}
