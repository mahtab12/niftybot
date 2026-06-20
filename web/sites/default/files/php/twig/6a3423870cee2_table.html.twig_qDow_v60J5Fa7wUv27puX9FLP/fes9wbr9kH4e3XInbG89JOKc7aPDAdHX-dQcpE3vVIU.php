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

/* core/themes/claro/templates/classy/dataset/table.html.twig */
class __TwigTemplate_349a5b57432124e37b59c237de0c82e7 extends Template
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
        // line 44
        yield "<table";
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["attributes"] ?? null), "html", null, true);
        yield ">
  ";
        // line 45
        if ((($tmp = ($context["caption"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 46
            yield "    <caption>";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["caption"] ?? null), "html", null, true);
            yield "</caption>
  ";
        }
        // line 48
        yield "
  ";
        // line 49
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["colgroups"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["colgroup"]) {
            // line 50
            yield "    ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["colgroup"], "cols", [], "any", false, false, true, 50)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 51
                yield "      <colgroup";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["colgroup"], "attributes", [], "any", false, false, true, 51), "html", null, true);
                yield ">
        ";
                // line 52
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["colgroup"], "cols", [], "any", false, false, true, 52));
                foreach ($context['_seq'] as $context["_key"] => $context["col"]) {
                    // line 53
                    yield "          <col";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["col"], "attributes", [], "any", false, false, true, 53), "html", null, true);
                    yield " />
        ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['col'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 55
                yield "      </colgroup>
    ";
            } else {
                // line 57
                yield "      <colgroup";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["colgroup"], "attributes", [], "any", false, false, true, 57), "html", null, true);
                yield " />
    ";
            }
            // line 59
            yield "  ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_key'], $context['colgroup'], $context['_parent']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 60
        yield "
  ";
        // line 61
        if ((($tmp = ($context["header"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 62
            yield "    <thead>
      <tr>
        ";
            // line 64
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["header"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["cell"]) {
                // line 65
                yield "          ";
                // line 66
                $context["cell_classes"] = [(((($tmp = CoreExtension::getAttribute($this->env, $this->source,                 // line 67
$context["cell"], "active_table_sort", [], "any", false, false, true, 67)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("is-active") : (""))];
                // line 70
                yield "          <";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "tag", [], "any", false, false, true, 70), "html", null, true);
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "attributes", [], "any", false, false, true, 70), "addClass", [($context["cell_classes"] ?? null)], "method", false, false, true, 70), "html", null, true);
                yield ">";
                // line 71
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "content", [], "any", false, false, true, 71), "html", null, true);
                // line 72
                yield "</";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "tag", [], "any", false, false, true, 72), "html", null, true);
                yield ">
        ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['cell'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 74
            yield "      </tr>
    </thead>
  ";
        }
        // line 77
        yield "
  ";
        // line 78
        if ((($tmp = ($context["rows"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 79
            yield "    <tbody>
      ";
            // line 80
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["rows"] ?? null));
            $context['loop'] = [
              'parent' => $context['_parent'],
              'index0' => 0,
              'index'  => 1,
              'first'  => true,
            ];
            if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
                $length = count($context['_seq']);
                $context['loop']['revindex0'] = $length - 1;
                $context['loop']['revindex'] = $length;
                $context['loop']['length'] = $length;
                $context['loop']['last'] = 1 === $length;
            }
            foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
                // line 81
                yield "        ";
                // line 82
                $context["row_classes"] = [(((($tmp =  !                // line 83
($context["no_striping"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? (Twig\Extension\CoreExtension::cycle(["odd", "even"], CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index0", [], "any", false, false, true, 83))) : (""))];
                // line 86
                yield "        <tr";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["row"], "attributes", [], "any", false, false, true, 86), "addClass", [($context["row_classes"] ?? null)], "method", false, false, true, 86), "html", null, true);
                yield ">
          ";
                // line 87
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "cells", [], "any", false, false, true, 87));
                foreach ($context['_seq'] as $context["_key"] => $context["cell"]) {
                    // line 88
                    yield "            <";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "tag", [], "any", false, false, true, 88), "html", null, true);
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "attributes", [], "any", false, false, true, 88), "html", null, true);
                    yield ">";
                    // line 89
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "content", [], "any", false, false, true, 89), "html", null, true);
                    // line 90
                    yield "</";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "tag", [], "any", false, false, true, 90), "html", null, true);
                    yield ">
          ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['cell'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 92
                yield "        </tr>
      ";
                ++$context['loop']['index0'];
                ++$context['loop']['index'];
                $context['loop']['first'] = false;
                if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                    --$context['loop']['revindex0'];
                    --$context['loop']['revindex'];
                    $context['loop']['last'] = 0 === $context['loop']['revindex0'];
                }
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['row'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 94
            yield "    </tbody>
  ";
        } elseif ((($tmp =         // line 95
($context["empty"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 96
            yield "    <tbody>
      <tr class=\"odd\">
        <td colspan=\"";
            // line 98
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["header_columns"] ?? null), "html", null, true);
            yield "\" class=\"empty message\">";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["empty"] ?? null), "html", null, true);
            yield "</td>
      </tr>
    </tbody>
  ";
        }
        // line 102
        yield "  ";
        if ((($tmp = ($context["footer"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 103
            yield "    <tfoot>
      ";
            // line 104
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["footer"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["row"]) {
                // line 105
                yield "        <tr";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["row"], "attributes", [], "any", false, false, true, 105), "html", null, true);
                yield ">
          ";
                // line 106
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["row"], "cells", [], "any", false, false, true, 106));
                foreach ($context['_seq'] as $context["_key"] => $context["cell"]) {
                    // line 107
                    yield "            <";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "tag", [], "any", false, false, true, 107), "html", null, true);
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "attributes", [], "any", false, false, true, 107), "html", null, true);
                    yield ">";
                    // line 108
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "content", [], "any", false, false, true, 108), "html", null, true);
                    // line 109
                    yield "</";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["cell"], "tag", [], "any", false, false, true, 109), "html", null, true);
                    yield ">
          ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['cell'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 111
                yield "        </tr>
      ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['row'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 113
            yield "    </tfoot>
  ";
        }
        // line 115
        yield "</table>
";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["attributes", "caption", "colgroups", "header", "rows", "no_striping", "loop", "empty", "header_columns", "footer"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "core/themes/claro/templates/classy/dataset/table.html.twig";
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
        return array (  273 => 115,  269 => 113,  262 => 111,  253 => 109,  251 => 108,  246 => 107,  242 => 106,  237 => 105,  233 => 104,  230 => 103,  227 => 102,  218 => 98,  214 => 96,  212 => 95,  209 => 94,  194 => 92,  185 => 90,  183 => 89,  178 => 88,  174 => 87,  169 => 86,  167 => 83,  166 => 82,  164 => 81,  147 => 80,  144 => 79,  142 => 78,  139 => 77,  134 => 74,  125 => 72,  123 => 71,  118 => 70,  116 => 67,  115 => 66,  113 => 65,  109 => 64,  105 => 62,  103 => 61,  100 => 60,  94 => 59,  88 => 57,  84 => 55,  75 => 53,  71 => 52,  66 => 51,  63 => 50,  59 => 49,  56 => 48,  50 => 46,  48 => 45,  43 => 44,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "core/themes/claro/templates/classy/dataset/table.html.twig", "/var/www/html/web/core/themes/claro/templates/classy/dataset/table.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["if" => 45, "for" => 49, "set" => 66];
        static $filters = ["escape" => 44];
        static $functions = ["cycle" => 83];

        try {
            $this->sandbox->checkSecurity(
                [0 => "if", 1 => "for", 2 => "set"],
                [0 => "escape"],
                [0 => "cycle"],
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
