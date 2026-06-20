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

/* core/themes/claro/templates/fieldset.html.twig */
class __TwigTemplate_da937d59b6f1e87ce27e740011842693 extends Template
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
        // line 30
        $context["classes"] = ["fieldset", (((($tmp = CoreExtension::getAttribute($this->env, $this->source,         // line 32
($context["attributes"] ?? null), "hasClass", ["fieldgroup"], "method", false, false, true, 32)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("fieldset--group") : ("")), "js-form-item", "form-item", "js-form-wrapper", "form-wrapper"];
        // line 40
        $context["wrapper_classes"] = ["fieldset__wrapper", (((($tmp = CoreExtension::getAttribute($this->env, $this->source,         // line 42
($context["attributes"] ?? null), "hasClass", ["fieldgroup"], "method", false, false, true, 42)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("fieldset__wrapper--group") : (""))];
        // line 46
        $context["legend_span_classes"] = ["fieldset__label", (((($tmp = CoreExtension::getAttribute($this->env, $this->source,         // line 48
($context["attributes"] ?? null), "hasClass", ["fieldgroup"], "method", false, false, true, 48)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("fieldset__label--group") : ("")), (((($tmp =         // line 49
($context["required"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("js-form-required") : ("")), (((($tmp =         // line 50
($context["required"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("form-required") : (""))];
        // line 54
        $context["legend_classes"] = ["fieldset__legend", (((CoreExtension::getAttribute($this->env, $this->source,         // line 56
($context["attributes"] ?? null), "hasClass", ["fieldgroup"], "method", false, false, true, 56) &&  !CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "hasClass", ["form-composite"], "method", false, false, true, 56))) ? ("fieldset__legend--group") : ("")), (((($tmp = CoreExtension::getAttribute($this->env, $this->source,         // line 57
($context["attributes"] ?? null), "hasClass", ["form-composite"], "method", false, false, true, 57)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("fieldset__legend--composite") : ("")), (((        // line 58
($context["title_display"] ?? null) == "invisible")) ? ("fieldset__legend--invisible") : ("fieldset__legend--visible"))];
        // line 62
        $context["description_classes"] = ["fieldset__description"];
        // line 66
        yield "
<fieldset";
        // line 67
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "addClass", [($context["classes"] ?? null)], "method", false, false, true, 67), "html", null, true);
        yield ">
  ";
        // line 69
        yield "  ";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["legend"] ?? null), "title", [], "any", false, false, true, 69)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 70
            yield "  <legend";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["legend"] ?? null), "attributes", [], "any", false, false, true, 70), "addClass", [($context["legend_classes"] ?? null)], "method", false, false, true, 70), "html", null, true);
            yield ">
    <span";
            // line 71
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["legend_span"] ?? null), "attributes", [], "any", false, false, true, 71), "addClass", [($context["legend_span_classes"] ?? null)], "method", false, false, true, 71), "html", null, true);
            yield ">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["legend"] ?? null), "title", [], "any", false, false, true, 71), "html", null, true);
            yield "</span>
  </legend>
  ";
        }
        // line 74
        yield "
  <div";
        // line 75
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["content_attributes"] ?? null), "addClass", [($context["wrapper_classes"] ?? null)], "method", false, false, true, 75), "html", null, true);
        yield ">
    ";
        // line 76
        if (((($context["description_display"] ?? null) == "before") && CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 76))) {
            // line 77
            yield "      <div";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "attributes", [], "any", false, false, true, 77), "addClass", [($context["description_classes"] ?? null)], "method", false, false, true, 77), "html", null, true);
            yield ">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 77), "html", null, true);
            yield "</div>
    ";
        }
        // line 79
        yield "    ";
        if ((($tmp = ($context["inline_items"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 80
            yield "      <div class=\"container-inline\">
    ";
        }
        // line 82
        yield "
    ";
        // line 83
        if ((($tmp = ($context["prefix"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 84
            yield "      <span class=\"fieldset__prefix\">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["prefix"] ?? null), "html", null, true);
            yield "</span>
    ";
        }
        // line 86
        yield "    ";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["children"] ?? null), "html", null, true);
        yield "
    ";
        // line 87
        if ((($tmp = ($context["suffix"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 88
            yield "      <span class=\"fieldset__suffix\">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["suffix"] ?? null), "html", null, true);
            yield "</span>
    ";
        }
        // line 90
        yield "    ";
        if ((($tmp = ($context["errors"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 91
            yield "      <div class=\"fieldset__error-message\">
        ";
            // line 92
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["errors"] ?? null), "html", null, true);
            yield "
      </div>
    ";
        }
        // line 95
        yield "    ";
        if ((CoreExtension::inFilter(($context["description_display"] ?? null), ["after", "invisible"]) && CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 95))) {
            // line 96
            yield "      <div";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "attributes", [], "any", false, false, true, 96), "addClass", [($context["description_classes"] ?? null)], "method", false, false, true, 96), "html", null, true);
            yield ">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 96), "html", null, true);
            yield "</div>
    ";
        }
        // line 98
        yield "
    ";
        // line 99
        if ((($tmp = ($context["inline_items"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 100
            yield "      </div>
    ";
        }
        // line 102
        yield "  </div>
</fieldset>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["attributes", "required", "title_display", "legend", "legend_span", "content_attributes", "description_display", "description", "inline_items", "prefix", "children", "suffix", "errors"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "core/themes/claro/templates/fieldset.html.twig";
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
        return array (  164 => 102,  160 => 100,  158 => 99,  155 => 98,  147 => 96,  144 => 95,  138 => 92,  135 => 91,  132 => 90,  126 => 88,  124 => 87,  119 => 86,  113 => 84,  111 => 83,  108 => 82,  104 => 80,  101 => 79,  93 => 77,  91 => 76,  87 => 75,  84 => 74,  76 => 71,  71 => 70,  68 => 69,  64 => 67,  61 => 66,  59 => 62,  57 => 58,  56 => 57,  55 => 56,  54 => 54,  52 => 50,  51 => 49,  50 => 48,  49 => 46,  47 => 42,  46 => 40,  44 => 32,  43 => 30,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "core/themes/claro/templates/fieldset.html.twig", "/var/www/html/web/core/themes/claro/templates/fieldset.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["set" => 30, "if" => 69];
        static $filters = ["escape" => 67];
        static $functions = [];

        try {
            $this->sandbox->checkSecurity(
                [0 => "set", 1 => "if"],
                [0 => "escape"],
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
