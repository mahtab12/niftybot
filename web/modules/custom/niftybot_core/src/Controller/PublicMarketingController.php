<?php

declare(strict_types=1);

namespace Drupal\niftybot_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\niftybot_core\Service\FaqContentService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public marketing pages for anonymous visitors (SEO & discovery).
 */
class PublicMarketingController extends ControllerBase {

  public function __construct(
    protected FaqContentService $faqContent,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('niftybot_core.faq_content'),
    );
  }

  /**
   * Algo trading features overview.
   */
  public function features(): array {
    return [
      '#theme' => 'niftybot_public_features',
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

  /**
   * Public pricing page.
   */
  public function pricing(): array {
    return [
      '#theme' => 'niftybot_public_pricing',
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

  /**
   * About GrassRed.
   */
  public function about(): array {
    return [
      '#theme' => 'niftybot_public_about',
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

  /**
   * Public FAQ for SEO and pre-signup visitors.
   */
  public function faq(): array {
    return [
      '#theme' => 'niftybot_public_faq',
      '#faq_sections' => $this->faqContent->getSections(public: TRUE),
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

  /**
   * XML sitemap for public marketing pages (SEO).
   */
  public function sitemap(): Response {
    $base = 'https://grassred.com';
    $paths = [
      ['loc' => $base . '/home', 'priority' => '1.0'],
      ['loc' => $base . '/features', 'priority' => '0.9'],
      ['loc' => $base . '/pricing', 'priority' => '0.9'],
      ['loc' => $base . '/about', 'priority' => '0.8'],
      ['loc' => $base . '/faq', 'priority' => '0.8'],
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($paths as $entry) {
      $xml .= '  <url>';
      $xml .= '<loc>' . htmlspecialchars($entry['loc'], ENT_XML1) . '</loc>';
      $xml .= '<changefreq>weekly</changefreq>';
      $xml .= '<priority>' . $entry['priority'] . '</priority>';
      $xml .= '</url>' . "\n";
    }
    $xml .= '</urlset>';

    return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
  }

}
