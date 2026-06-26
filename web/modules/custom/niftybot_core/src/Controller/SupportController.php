<?php

declare(strict_types=1);

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Support and FAQ pages for traders.
 */
class SupportController extends ControllerBase {

  /**
   * Support centre page.
   */
  public function support(): array {
    $site_mail = (string) $this->config('system.site')->get('mail');
    $site_name = (string) $this->config('system.site')->get('name');
    if ($site_name === '' || $site_name === 'Drush Site-Install') {
      $site_name = 'StrikeFlow';
    }

    return [
      '#theme' => 'niftybot_support',
      '#site_name' => $site_name,
      '#support_email' => $site_mail !== '' ? $site_mail : 'support@strikeflow.example',
      '#quick_links' => [
        [
          'title' => $this->t('FAQ'),
          'description' => $this->t('Answers to common questions about trading, wallet, and KYC.'),
          'route' => 'niftybot_core.faq',
          'icon' => 'bi-question-circle',
        ],
        [
          'title' => $this->t('KYC verification'),
          'description' => $this->t('Submit or check the status of your identity verification.'),
          'route' => 'niftybot_user.kyc_submit',
          'icon' => 'bi-shield-check',
        ],
        [
          'title' => $this->t('Wallet'),
          'description' => $this->t('Deposit funds, request withdrawals, and view transaction history.'),
          'route' => 'niftybot_user.wallet',
          'icon' => 'bi-wallet2',
        ],
        [
          'title' => $this->t('My profile'),
          'description' => $this->t('Update your contact details and account information.'),
          'route' => 'niftybot_user.profile',
          'icon' => 'bi-person',
        ],
      ],
      '#attached' => [
        'library' => ['niftybot_core/support'],
      ],
    ];
  }

  /**
   * Frequently asked questions page.
   */
  public function faq(): array {
    return [
      '#theme' => 'niftybot_faq',
      '#faq_sections' => $this->getFaqSections(),
      '#attached' => [
        'library' => ['niftybot_core/support'],
      ],
    ];
  }

  /**
   * FAQ content grouped by section.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function getFaqSections(): array {
    return [
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
        'title' => $this->t('Trading'),
        'items' => [
          [
            'question' => $this->t('How do I place an order?'),
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
            'answer' => $this->t('An active subscription plan is required for full platform access. View available plans under Plans in the sidebar.'),
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
            'question' => $this->t('What is AI investment?'),
            'answer' => $this->t('AI investment lets you allocate capital to managed strategies on the platform. Returns and terms depend on the plan you select.'),
          ],
          [
            'question' => $this->t('How is profit calculated and paid?'),
            'answer' => $this->t('Profit accrues according to your investment plan. Pending and total earned amounts are shown on your dashboard and investment pages.'),
          ],
        ],
      ],
    ];
  }

}
