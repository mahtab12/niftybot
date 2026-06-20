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

/* modules/custom/niftybot_user/templates/niftybot-kyc-admin-list.html.twig */
class __TwigTemplate_2a3b8ae0e9ab46eb6efb2ed5a6871691 extends Template
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
        yield "<div class=\"niftybot-kyc-admin\">
  <h2>";
        // line 2
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("KYC Verification Requests"));
        yield "</h2>

  ";
        // line 4
        if ((Twig\Extension\CoreExtension::length($this->env->getCharset(), ($context["kyc_records"] ?? null)) > 0)) {
            // line 5
            yield "    <table class=\"niftybot-table\">
      <thead>
        <tr>
          <th>";
            // line 8
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("ID"));
            yield "</th>
          <th>";
            // line 9
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("User"));
            yield "</th>
          <th>";
            // line 10
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Email"));
            yield "</th>
          <th>";
            // line 11
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Name"));
            yield "</th>
          <th>";
            // line 12
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("PAN"));
            yield "</th>
          <th>";
            // line 13
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Status"));
            yield "</th>
          <th>";
            // line 14
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Submitted"));
            yield "</th>
          <th>";
            // line 15
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Actions"));
            yield "</th>
        </tr>
      </thead>
      <tbody>
        ";
            // line 19
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["kyc_records"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["kyc"]) {
                // line 20
                yield "          <tr>
            <td>";
                // line 21
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "kyc_id", [], "any", false, false, true, 21), "html", null, true);
                yield "</td>
            <td>";
                // line 22
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "username", [], "any", false, false, true, 22), "html", null, true);
                yield "</td>
            <td>";
                // line 23
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "email", [], "any", false, false, true, 23), "html", null, true);
                yield "</td>
            <td>";
                // line 24
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "full_name", [], "any", false, false, true, 24), "html", null, true);
                yield "</td>
            <td>";
                // line 25
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "pan_number", [], "any", false, false, true, 25), "html", null, true);
                yield "</td>
            <td>
              <span class=\"kyc-status kyc-status--";
                // line 27
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "status", [], "any", false, false, true, 27), "html", null, true);
                yield "\">
                ";
                // line 28
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::capitalize($this->env->getCharset(), Twig\Extension\CoreExtension::replace(CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "status", [], "any", false, false, true, 28), ["_" => " "])), "html", null, true);
                yield "
              </span>
            </td>
            <td>";
                // line 31
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "created", [], "any", false, false, true, 31), "d M Y"), "html", null, true);
                yield "</td>
            <td>
              <a href=\"";
                // line 33
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["kyc"], "review_url", [], "any", false, false, true, 33), "html", null, true);
                yield "\" class=\"button button--small\">";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Review"));
                yield "</a>
            </td>
          </tr>
        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['kyc'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 37
            yield "      </tbody>
    </table>
  ";
        } else {
            // line 40
            yield "    <p class=\"empty-state\">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("No KYC submissions yet."));
            yield "</p>
  ";
        }
        // line 42
        yield "</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["kyc_records"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "modules/custom/niftybot_user/templates/niftybot-kyc-admin-list.html.twig";
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
        return array (  159 => 42,  153 => 40,  148 => 37,  136 => 33,  131 => 31,  125 => 28,  121 => 27,  116 => 25,  112 => 24,  108 => 23,  104 => 22,  100 => 21,  97 => 20,  93 => 19,  86 => 15,  82 => 14,  78 => 13,  74 => 12,  70 => 11,  66 => 10,  62 => 9,  58 => 8,  53 => 5,  51 => 4,  46 => 2,  43 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "modules/custom/niftybot_user/templates/niftybot-kyc-admin-list.html.twig", "/var/www/html/web/modules/custom/niftybot_user/templates/niftybot-kyc-admin-list.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["if" => 4, "for" => 19];
        static $filters = ["t" => 2, "length" => 4, "escape" => 21, "capitalize" => 28, "replace" => 28, "date" => 31];
        static $functions = [];

        try {
            $this->sandbox->checkSecurity(
                [0 => "if", 1 => "for"],
                [0 => "t", 1 => "length", 2 => "escape", 3 => "capitalize", 4 => "replace", 5 => "date"],
                [],
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
