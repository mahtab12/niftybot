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

/* modules/custom/niftybot_trading/templates/niftybot-orders-list.html.twig */
class __TwigTemplate_f0b8e82fa7a3cc1e93f651dd01f93fd3 extends Template
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
        yield "<div class=\"niftybot-orders\">
  <div class=\"orders-header\">
    <a href=\"";
        // line 3
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($this->extensions['Drupal\Core\Template\TwigExtension']->getPath("niftybot_trading.order_form"));
        yield "\" class=\"button button--primary\">";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("+ Place New Order"));
        yield "</a>
  </div>

  ";
        // line 6
        if ((Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["orders"] ?? null)) > 0)) {
            // line 7
            yield "    <table class=\"niftybot-table orders-table\">
      <thead>
        <tr>
          ";
            // line 10
            if ((($tmp = ($context["is_admin"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                yield "<th>";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("User"));
                yield "</th>";
            }
            // line 11
            yield "          <th>";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Symbol"));
            yield "</th>
          <th>";
            // line 12
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Exchange"));
            yield "</th>
          <th>";
            // line 13
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Type"));
            yield "</th>
          <th>";
            // line 14
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Order"));
            yield "</th>
          <th>";
            // line 15
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Qty"));
            yield "</th>
          <th>";
            // line 16
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Price"));
            yield "</th>
          <th>";
            // line 17
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Status"));
            yield "</th>
          <th>";
            // line 18
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Broker"));
            yield "</th>
          <th>";
            // line 19
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Date"));
            yield "</th>
          <th>";
            // line 20
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Actions"));
            yield "</th>
        </tr>
      </thead>
      <tbody>
        ";
            // line 24
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["orders"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["order"]) {
                // line 25
                yield "          <tr class=\"order-row order-status--";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "status", [], "any", false, false, true, 25), "html", null, true);
                yield "\">
            ";
                // line 26
                if ((($tmp = ($context["is_admin"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    yield "<td>";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "username", [], "any", false, false, true, 26), "html", null, true);
                    yield "</td>";
                }
                // line 27
                yield "            <td class=\"order-symbol\">";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "symbol", [], "any", false, false, true, 27), "html", null, true);
                yield "</td>
            <td>";
                // line 28
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "exchange", [], "any", false, false, true, 28), "html", null, true);
                yield "</td>
            <td>
              <span class=\"txn-type txn-type--";
                // line 30
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::lower($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["order"], "transaction_type", [], "any", false, false, true, 30)), "html", null, true);
                yield "\">
                ";
                // line 31
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "transaction_type", [], "any", false, false, true, 31), "html", null, true);
                yield "
              </span>
            </td>
            <td>";
                // line 34
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "order_type", [], "any", false, false, true, 34), "html", null, true);
                yield "</td>
            <td>";
                // line 35
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "quantity", [], "any", false, false, true, 35), "html", null, true);
                yield "</td>
            <td>
              ";
                // line 37
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["order"], "executed_price", [], "any", false, false, true, 37)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 38
                    yield "                ₹";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["order"], "executed_price", [], "any", false, false, true, 38), 2), "html", null, true);
                    yield "
              ";
                } elseif ((($tmp = CoreExtension::getAttribute($this->env, $this->source,                 // line 39
$context["order"], "price", [], "any", false, false, true, 39)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 40
                    yield "                ₹";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Twig\Extension\CoreExtension']->formatNumber(CoreExtension::getAttribute($this->env, $this->source, $context["order"], "price", [], "any", false, false, true, 40), 2), "html", null, true);
                    yield "
              ";
                } else {
                    // line 42
                    yield "                ";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Market"));
                    yield "
              ";
                }
                // line 44
                yield "            </td>
            <td>
              <span class=\"order-status order-status--";
                // line 46
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "status", [], "any", false, false, true, 46), "html", null, true);
                yield "\">
                ";
                // line 47
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::capitalize($this->env->getCharset(), Twig\Extension\CoreExtension::replace(CoreExtension::getAttribute($this->env, $this->source, $context["order"], "status", [], "any", false, false, true, 47), ["_" => " "])), "html", null, true);
                yield "
              </span>
            </td>
            <td>";
                // line 50
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::capitalize($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["order"], "broker", [], "any", false, false, true, 50)), "html", null, true);
                yield "</td>
            <td>";
                // line 51
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, $context["order"], "placed_at", [], "any", false, false, true, 51), "d M, h:i A"), "html", null, true);
                yield "</td>
            <td>
              <a href=\"";
                // line 53
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "detail_url", [], "any", false, false, true, 53), "html", null, true);
                yield "\">";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("View"));
                yield "</a>
              ";
                // line 54
                if ((CoreExtension::getAttribute($this->env, $this->source, $context["order"], "cancel_url", [], "any", true, true, true, 54) && CoreExtension::getAttribute($this->env, $this->source, $context["order"], "cancel_url", [], "any", false, false, true, 54))) {
                    // line 55
                    yield "                | <a href=\"";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["order"], "cancel_url", [], "any", false, false, true, 55), "html", null, true);
                    yield "\" class=\"cancel-link\">";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Cancel"));
                    yield "</a>
              ";
                }
                // line 57
                yield "            </td>
          </tr>
        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['order'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 60
            yield "      </tbody>
    </table>
  ";
        } else {
            // line 63
            yield "    <div class=\"empty-state\">
      <p>";
            // line 64
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("No orders yet. Place your first order to get started."));
            yield "</p>
    </div>
  ";
        }
        // line 67
        yield "</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["orders", "is_admin"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "modules/custom/niftybot_trading/templates/niftybot-orders-list.html.twig";
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
        return array (  238 => 67,  232 => 64,  229 => 63,  224 => 60,  216 => 57,  208 => 55,  206 => 54,  200 => 53,  195 => 51,  191 => 50,  185 => 47,  181 => 46,  177 => 44,  171 => 42,  165 => 40,  163 => 39,  158 => 38,  156 => 37,  151 => 35,  147 => 34,  141 => 31,  137 => 30,  132 => 28,  127 => 27,  121 => 26,  116 => 25,  112 => 24,  105 => 20,  101 => 19,  97 => 18,  93 => 17,  89 => 16,  85 => 15,  81 => 14,  77 => 13,  73 => 12,  68 => 11,  62 => 10,  57 => 7,  55 => 6,  47 => 3,  43 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "modules/custom/niftybot_trading/templates/niftybot-orders-list.html.twig", "/var/www/html/web/modules/custom/niftybot_trading/templates/niftybot-orders-list.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["if" => 6, "for" => 24];
        static $filters = ["t" => 3, "length" => 6, "escape" => 25, "lower" => 30, "number_format" => 38, "capitalize" => 47, "replace" => 47, "date" => 51];
        static $functions = ["path" => 3];

        try {
            $this->sandbox->checkSecurity(
                [0 => "if", 1 => "for"],
                [0 => "t", 1 => "length", 2 => "escape", 3 => "lower", 4 => "number_format", 5 => "capitalize", 6 => "replace", 7 => "date"],
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
