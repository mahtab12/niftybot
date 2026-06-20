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

/* core/themes/claro/templates/form-element.html.twig */
class __TwigTemplate_8ca31b9052327b64bdb3f2dccd98598d extends Template
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
        // line 14
        $context["classes"] = ["js-form-item", "form-item", ("js-form-type-" . \Drupal\Component\Utility\Html::getClass(        // line 17
($context["type"] ?? null))), ("form-type--" . \Drupal\Component\Utility\Html::getClass(        // line 18
($context["type"] ?? null))), ((CoreExtension::inFilter(        // line 19
($context["type"] ?? null), ["checkbox", "radio"])) ? ("form-type--boolean") : ("")), ("js-form-item-" . \Drupal\Component\Utility\Html::getClass(        // line 20
($context["name"] ?? null))), ("form-item--" . \Drupal\Component\Utility\Html::getClass(        // line 21
($context["name"] ?? null))), ((!CoreExtension::inFilter(        // line 22
($context["title_display"] ?? null), ["after", "before"])) ? ("form-item--no-label") : ("")), (((        // line 23
($context["disabled"] ?? null) == "disabled")) ? ("form-item--disabled") : ("")), (((($tmp =         // line 24
($context["errors"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("form-item--error") : (""))];
        // line 28
        $context["description_classes"] = ["form-item__description", (((        // line 30
($context["description_display"] ?? null) == "invisible")) ? ("visually-hidden") : (""))];
        // line 33
        yield "<div";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "addClass", [($context["classes"] ?? null)], "method", false, false, true, 33), "html", null, true);
        yield ">
  ";
        // line 34
        if (CoreExtension::inFilter(($context["label_display"] ?? null), ["before", "invisible"])) {
            // line 35
            yield "    ";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["label"] ?? null), "html", null, true);
            yield "
  ";
        }
        // line 37
        yield "  ";
        if (((($context["description_display"] ?? null) == "before") && CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 37))) {
            // line 38
            yield "    <div";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "attributes", [], "any", false, false, true, 38), "addClass", [($context["description_classes"] ?? null)], "method", false, false, true, 38), "html", null, true);
            yield ">
      ";
            // line 39
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 39), "html", null, true);
            yield "
    </div>
  ";
        }
        // line 42
        yield "  ";
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["prefix"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 43
            yield "    <span class=\"form-item__prefix";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar((((($context["disabled"] ?? null) == "disabled")) ? (" is-disabled") : ("")));
            yield "\">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["prefix"] ?? null), "html", null, true);
            yield "</span>
  ";
        }
        // line 45
        yield "  ";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["children"] ?? null), "html", null, true);
        yield "
  ";
        // line 46
        if ((($tmp =  !Twig\Extension\CoreExtension::testEmpty(($context["suffix"] ?? null))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 47
            yield "    <span class=\"form-item__suffix";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar((((($context["disabled"] ?? null) == "disabled")) ? (" is-disabled") : ("")));
            yield "\">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["suffix"] ?? null), "html", null, true);
            yield "</span>
  ";
        }
        // line 49
        yield "  ";
        if ((($context["label_display"] ?? null) == "after")) {
            // line 50
            yield "    ";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["label"] ?? null), "html", null, true);
            yield "
  ";
        }
        // line 52
        yield "  ";
        if ((($tmp = ($context["errors"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 53
            yield "    <div class=\"form-item__error-message\">
      ";
            // line 54
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["errors"] ?? null), "html", null, true);
            yield "
    </div>
  ";
        }
        // line 57
        yield "  ";
        if ((CoreExtension::inFilter(($context["description_display"] ?? null), ["after", "invisible"]) && CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 57))) {
            // line 58
            yield "    <div";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "attributes", [], "any", false, false, true, 58), "addClass", [($context["description_classes"] ?? null)], "method", false, false, true, 58), "html", null, true);
            yield ">
      ";
            // line 59
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["description"] ?? null), "content", [], "any", false, false, true, 59), "html", null, true);
            yield "
    </div>
  ";
        }
        // line 62
        yield "</div>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["type", "name", "title_display", "disabled", "errors", "description_display", "attributes", "label_display", "label", "description", "prefix", "children", "suffix"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "core/themes/claro/templates/form-element.html.twig";
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
        return array (  144 => 62,  138 => 59,  133 => 58,  130 => 57,  124 => 54,  121 => 53,  118 => 52,  112 => 50,  109 => 49,  101 => 47,  99 => 46,  94 => 45,  86 => 43,  83 => 42,  77 => 39,  72 => 38,  69 => 37,  63 => 35,  61 => 34,  56 => 33,  54 => 30,  53 => 28,  51 => 24,  50 => 23,  49 => 22,  48 => 21,  47 => 20,  46 => 19,  45 => 18,  44 => 17,  43 => 14,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "core/themes/claro/templates/form-element.html.twig", "/var/www/html/web/core/themes/claro/templates/form-element.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["set" => 14, "if" => 34];
        static $filters = ["clean_class" => 17, "escape" => 33];
        static $functions = [];

        try {
            $this->sandbox->checkSecurity(
                [0 => "set", 1 => "if"],
                [0 => "clean_class", 1 => "escape"],
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
