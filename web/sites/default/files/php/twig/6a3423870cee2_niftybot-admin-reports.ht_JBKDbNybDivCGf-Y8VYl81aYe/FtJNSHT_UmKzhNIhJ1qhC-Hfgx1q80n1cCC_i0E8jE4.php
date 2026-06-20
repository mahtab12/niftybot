<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* modules/custom/niftybot_reports/templates/niftybot-admin-reports.html.twig */
class __TwigTemplate_f20e546963105dc67c9e64e4d8d2451e extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 1
        yield "<div class=\"niftybot-admin-reports\">
  <h2>";
        // line 2
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Platform Reports"));
        yield "</h2>

  <div class=\"platform-stats\">
    <div class=\"stat-card\">
      <div class=\"stat-value\">";
        // line 6
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["platform_stats"] ?? null), "total_users", [], "any", false, false, true, 6), "html", null, true);
        yield "</div>
      <div class=\"stat-label\">";
        // line 7
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Total Users"));
        yield "</div>
    </div>
    <div class=\"stat-card\">
      <div class=\"stat-value\">";
        // line 10
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["platform_stats"] ?? null), "active_subscriptions", [], "any", false, false, true, 10), "html", null, true);
        yield "</div>
      <div class=\"stat-label\">";
        // line 11
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Active Subscriptions"));
        yield "</div>
    </div>
    <div class=\"stat-card\">
      <div class=\"stat-value\">";
        // line 14
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["platform_stats"] ?? null), "total_orders", [], "any", false, false, true, 14), "html", null, true);
        yield "</div>
      <div class=\"stat-label\">";
        // line 15
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Total Orders"));
        yield "</div>
    </div>
    <div class=\"stat-card\">
      <div class=\"stat-value\">₹";
        // line 18
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, ($context["platform_stats"] ?? null), "total_revenue", [], "any", false, false, true, 18), 2), "html", null, true);
        yield "</div>
      <div class=\"stat-label\">";
        // line 19
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Total Revenue"));
        yield "</div>
    </div>
  </div>

  <div class=\"admin-quick-links\">
    <h3>";
        // line 24
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Management"));
        yield "</h3>
    <ul>
      <li><a href=\"";
        // line 26
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("niftybot_user.kyc_admin"));
        yield "\">";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("KYC Approvals"));
        yield "</a></li>
      <li><a href=\"";
        // line 27
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("niftybot_subscription.admin_subscribers"));
        yield "\">";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Subscribers"));
        yield "</a></li>
      <li><a href=\"";
        // line 28
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("niftybot_trading.admin_orders"));
        yield "\">";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("All Orders"));
        yield "</a></li>
      <li><a href=\"";
        // line 29
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("niftybot_core.settings"));
        yield "\">";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Platform Settings"));
        yield "</a></li>
    </ul>
  </div>
</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["platform_stats"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "modules/custom/niftybot_reports/templates/niftybot-admin-reports.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  118 => 29,  112 => 28,  106 => 27,  100 => 26,  95 => 24,  87 => 19,  83 => 18,  77 => 15,  73 => 14,  67 => 11,  63 => 10,  57 => 7,  53 => 6,  46 => 2,  43 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "modules/custom/niftybot_reports/templates/niftybot-admin-reports.html.twig", "/var/www/html/web/modules/custom/niftybot_reports/templates/niftybot-admin-reports.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = [];
        static $filters = ["t" => 2, "escape" => 6, "number_format" => 18];
        static $functions = ["path" => 26];

        try {
            $this->sandbox->checkSecurity(
                [],
                [0 => "t", 1 => "escape", 2 => "number_format"],
                [0 => "path"],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
