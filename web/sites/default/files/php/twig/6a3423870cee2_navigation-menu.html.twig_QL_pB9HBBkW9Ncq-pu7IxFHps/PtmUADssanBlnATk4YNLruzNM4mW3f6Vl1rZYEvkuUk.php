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

/* core/modules/navigation/templates/navigation-menu.html.twig */
class __TwigTemplate_8e580ec5ad628d85e9de55b38a7f9872 extends Template
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
        $macros["menus"] = $this->macros["menus"] = $this;
        // line 2
        yield "<ul class=\"toolbar-block__list\">
  ";
        // line 3
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_items", $context, 3, $this->getSourceContext())->macro_menu_items(...[($context["items"] ?? null), ($context["attributes"] ?? null), 0]));
        yield "
</ul>

";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["_self", "items", "attributes", "menu_level", "v"]);        yield from [];
    }

    // line 6
    public function macro_menu_items($items = null, $attributes = null, $menu_level = null, ...$varargs): string|Markup
    {
        $macros = $this->macros;
        $context = [
            "items" => $items,
            "attributes" => $attributes,
            "menu_level" => $menu_level,
            "varargs" => $varargs,
        ] + $this->env->getGlobals();

        $blocks = [];

        return ('' === $tmp = implode('', iterator_to_array((function () use (&$context, $macros, $blocks) {
            // line 7
            yield "  ";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
            foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
                // line 8
                yield "
    ";
                // line 9
                $context["item_link_tag"] = "a";
                // line 10
                yield "
    ";
                // line 11
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 11), "isRouted", [], "any", false, false, true, 11)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 12
                    yield "      ";
                    if ((CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 12), "routeName", [], "any", false, false, true, 12) == "<nolink>")) {
                        // line 13
                        yield "        ";
                        $context["item_link_tag"] = Twig\Extension\CoreExtension::constant("\\Drupal\\Core\\GeneratedNoLink::TAG");
                        // line 14
                        yield "      ";
                    } elseif ((CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 14), "routeName", [], "any", false, false, true, 14) == "<button>")) {
                        // line 15
                        yield "        ";
                        $context["item_link_tag"] = Twig\Extension\CoreExtension::constant("\\Drupal\\Core\\GeneratedButton::TAG");
                        // line 16
                        yield "      ";
                    }
                    // line 17
                    yield "    ";
                }
                // line 18
                yield "
    ";
                // line 19
                if (Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 19), "options", [], "any", false, false, true, 19), "attributes", [], "any", false, false, true, 19))) {
                    // line 20
                    yield "      ";
                    $context["item_link_attributes"] = $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute();
                    // line 21
                    yield "    ";
                } else {
                    // line 22
                    yield "      ";
                    $context["item_link_attributes"] = $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 22), "options", [], "any", false, false, true, 22), "attributes", [], "any", false, false, true, 22));
                    // line 23
                    yield "    ";
                }
                // line 24
                yield "
    ";
                // line 25
                $context["item_id"] = (("navigation-link--" . (((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "original_link", [], "any", false, false, true, 25), "pluginId", [], "any", false, false, true, 25)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ((\Drupal\Component\Utility\Html::getClass(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "original_link", [], "any", false, false, true, 25), "pluginId", [], "any", false, false, true, 25)) . "-")) : (""))) . Twig\Extension\CoreExtension::random($this->env->getCharset()));
                // line 26
                yield "    ";
                if ((($context["menu_level"] ?? null) == 0)) {
                    // line 27
                    yield "      ";
                    if (Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 27))) {
                        // line 28
                        yield "        <li id=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["item_id"] ?? null), "html", null, true);
                        yield "\" class=\"toolbar-block__list-item\">
          ";
                        // line 29
                        yield from $this->load("navigation:toolbar-button", 29)->unwrap()->yield(CoreExtension::toArray(["attributes" => CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source,                         // line 30
($context["item_link_attributes"] ?? null), "setAttribute", ["href", Twig\Extension\CoreExtension::default($this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 30)), null)], "method", false, false, true, 30), "setAttribute", ["data-drupal-tooltip", CoreExtension::getAttribute($this->env, $this->source, $context["item"], "title", [], "any", false, false, true, 30)], "method", false, false, true, 30), "setAttribute", ["data-drupal-tooltip-class", "admin-toolbar__tooltip"], "method", false, false, true, 30), "icon" => CoreExtension::getAttribute($this->env, $this->source,                         // line 31
$context["item"], "icon", [], "any", false, false, true, 31), "html_tag" =>                         // line 32
($context["item_link_tag"] ?? null), "text" => CoreExtension::getAttribute($this->env, $this->source,                         // line 33
$context["item"], "title", [], "any", false, false, true, 33), "modifiers" => Twig\Extension\CoreExtension::filter($this->env, $this->env->hasExtension(\Twig\Extension\SandboxExtension::class) && $this->env->getExtension(\Twig\Extension\SandboxExtension::class)->isSandboxed($this->source), ["collapsible", (((                        // line 36
($context["item_link_tag"] ?? null) == "span")) ? ("non-interactive") : (null))],                         // line 37
function ($__v__) use ($context, $macros) { $context["v"] = $__v__; return  !(null === ($context["v"] ?? null)); })]));
                        // line 39
                        yield "        </li>
      ";
                    } else {
                        // line 41
                        yield "        <li id=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["item_id"] ?? null), "html", null, true);
                        yield "\" class=\"toolbar-block__list-item toolbar-popover\" data-toolbar-popover>
          ";
                        // line 42
                        yield from $this->load("navigation:toolbar-button", 42)->unwrap()->yield(CoreExtension::toArray(["action" => t("Extend"), "attributes" => $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute(["aria-expanded" => "false", "aria-controls" =>                         // line 46
($context["item_id"] ?? null), "data-toolbar-popover-control" => true, "data-has-safe-triangle" => true]), "icon" => CoreExtension::getAttribute($this->env, $this->source,                         // line 50
$context["item"], "icon", [], "any", false, false, true, 50), "text" => CoreExtension::getAttribute($this->env, $this->source,                         // line 51
$context["item"], "title", [], "any", false, false, true, 51), "modifiers" => ["expand--side", "collapsible"], "extra_classes" => ["toolbar-popover__control"]]));
                        // line 60
                        yield "          <div class=\"toolbar-popover__wrapper\" data-toolbar-popover-wrapper inert>
            ";
                        // line 61
                        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 61)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                            // line 62
                            yield "              ";
                            yield from $this->load("navigation:toolbar-button", 62)->unwrap()->yield(CoreExtension::toArray(["attributes" => CoreExtension::getAttribute($this->env, $this->source,                             // line 63
($context["item_link_attributes"] ?? null), "setAttribute", ["href", $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 63))], "method", false, false, true, 63), "html_tag" =>                             // line 64
($context["item_link_tag"] ?? null), "text" => CoreExtension::getAttribute($this->env, $this->source,                             // line 65
$context["item"], "title", [], "any", false, false, true, 65), "modifiers" => ["large", "dark"], "extra_classes" => ["toolbar-popover__header"]]));
                            // line 74
                            yield "            ";
                        } else {
                            // line 75
                            yield "              ";
                            yield from $this->load("navigation:toolbar-button", 75)->unwrap()->yield(CoreExtension::toArray(["attributes" => $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute(), "modifiers" => ["large", "dark", "non-interactive"], "extra_classes" => ["toolbar-popover__header"], "html_tag" => "span", "text" => CoreExtension::getAttribute($this->env, $this->source,                             // line 86
$context["item"], "title", [], "any", false, false, true, 86)]));
                            // line 88
                            yield "            ";
                        }
                        // line 89
                        yield "            <ul class=\"toolbar-menu toolbar-popover__menu\">
              ";
                        // line 90
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_items", $context, 90, $this->getSourceContext())->macro_menu_items(...[CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 90), ($context["attributes"] ?? null), 1]));
                        yield "
            </ul>
          </div>
        </li>
      ";
                    }
                    // line 95
                    yield "
    ";
                } elseif ((                // line 96
($context["menu_level"] ?? null) == 1)) {
                    // line 97
                    yield "      <li class=\"toolbar-menu__item toolbar-menu__item--level-";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["menu_level"] ?? null), "html", null, true);
                    yield "\">
        ";
                    // line 98
                    if (Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 98))) {
                        // line 99
                        yield "          ";
                        yield from $this->load("navigation:toolbar-button", 99)->unwrap()->yield(CoreExtension::toArray(["attributes" => CoreExtension::getAttribute($this->env, $this->source,                         // line 100
($context["item_link_attributes"] ?? null), "setAttribute", ["href", Twig\Extension\CoreExtension::default($this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 100)), null)], "method", false, false, true, 100), "text" => CoreExtension::getAttribute($this->env, $this->source,                         // line 101
$context["item"], "title", [], "any", false, false, true, 101), "html_tag" =>                         // line 102
($context["item_link_tag"] ?? null), "extra_classes" => [(((                        // line 104
($context["item_link_tag"] ?? null) == "span")) ? ("toolbar-button--non-interactive") : (""))]]));
                        // line 107
                        yield "        ";
                    } else {
                        // line 108
                        yield "          ";
                        yield from $this->load("navigation:toolbar-button", 108)->unwrap()->yield(CoreExtension::toArray(["attributes" => $this->extensions['Drupal\Core\Template\TwigExtension']->createAttribute(["aria-expanded" => "false", "data-toolbar-menu-trigger" =>                         // line 111
($context["menu_level"] ?? null)]), "text" => CoreExtension::getAttribute($this->env, $this->source,                         // line 113
$context["item"], "title", [], "any", false, false, true, 113), "modifiers" => ["expand--down"]]));
                        // line 116
                        yield "          <ul class=\"toolbar-menu toolbar-menu--level-";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, (($context["menu_level"] ?? null) + 1), "html", null, true);
                        yield "\" inert>
            ";
                        // line 117
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_items", $context, 117, $this->getSourceContext())->macro_menu_items(...[CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 117), ($context["attributes"] ?? null), (($context["menu_level"] ?? null) + 1)]));
                        yield "
          </ul>
        ";
                    }
                    // line 120
                    yield "      </li>
    ";
                } else {
                    // line 122
                    yield "      <li class=\"toolbar-menu__item toolbar-menu__item--level-";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["menu_level"] ?? null), "html", null, true);
                    yield "\">
        ";
                    // line 123
                    if (Twig\Extension\CoreExtension::testEmpty(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 123))) {
                        // line 124
                        yield "          ";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\TwigExtension']->getLink(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "title", [], "any", false, false, true, 124), CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 124), ["class" => ["toolbar-menu__link", ("toolbar-menu__link--" .                         // line 127
($context["menu_level"] ?? null))], "data-index-text" => Twig\Extension\CoreExtension::lower($this->env->getCharset(), Twig\Extension\CoreExtension::first($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source,                         // line 129
$context["item"], "title", [], "any", false, false, true, 129)))]), "html", null, true);
                        // line 130
                        yield "
        ";
                    } else {
                        // line 132
                        yield "          <button
            class=\"toolbar-menu__link toolbar-menu__link--";
                        // line 133
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["menu_level"] ?? null), "html", null, true);
                        yield "\"
            data-toolbar-menu-trigger=\"";
                        // line 134
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["menu_level"] ?? null), "html", null, true);
                        yield "\"
            aria-expanded=\"false\"
            data-index-text=\"";
                        // line 136
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::lower($this->env->getCharset(), Twig\Extension\CoreExtension::first($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, $context["item"], "title", [], "any", false, false, true, 136))), "html", null, true);
                        yield "\"
          >
            <span data-toolbar-action class=\"toolbar-menu__link-action visually-hidden\">";
                        // line 138
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(t("Extend"));
                        yield "</span>
            <span class=\"toolbar-menu__link-title\">";
                        // line 139
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "title", [], "any", false, false, true, 139), "html", null, true);
                        yield "</span>
            ";
                        // line 140
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\IconsTwigExtension']->getIconRenderable("navigation", "chevron", ["class" => "toolbar-menu__chevron", "size" => 16]), "html", null, true);
                        yield "
          </button>
          <ul class=\"toolbar-menu toolbar-menu--level-";
                        // line 142
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, (($context["menu_level"] ?? null) + 1), "html", null, true);
                        yield "\" inert>
            ";
                        // line 143
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_items", $context, 143, $this->getSourceContext())->macro_menu_items(...[CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 143), ($context["attributes"] ?? null), (($context["menu_level"] ?? null) + 1)]));
                        yield "
          </ul>
        ";
                    }
                    // line 146
                    yield "      </li>
    ";
                }
                // line 148
                yield "  ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            yield from [];
        })(), false))) ? '' : new Markup($tmp, $this->env->getCharset());
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "core/modules/navigation/templates/navigation-menu.html.twig";
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
        return array (  294 => 148,  290 => 146,  284 => 143,  280 => 142,  275 => 140,  271 => 139,  267 => 138,  262 => 136,  257 => 134,  253 => 133,  250 => 132,  246 => 130,  244 => 129,  243 => 127,  241 => 124,  239 => 123,  234 => 122,  230 => 120,  224 => 117,  219 => 116,  217 => 113,  216 => 111,  214 => 108,  211 => 107,  209 => 104,  208 => 102,  207 => 101,  206 => 100,  204 => 99,  202 => 98,  197 => 97,  195 => 96,  192 => 95,  184 => 90,  181 => 89,  178 => 88,  176 => 86,  174 => 75,  171 => 74,  169 => 65,  168 => 64,  167 => 63,  165 => 62,  163 => 61,  160 => 60,  158 => 51,  157 => 50,  156 => 46,  155 => 42,  150 => 41,  146 => 39,  144 => 37,  143 => 36,  142 => 33,  141 => 32,  140 => 31,  139 => 30,  138 => 29,  133 => 28,  130 => 27,  127 => 26,  125 => 25,  122 => 24,  119 => 23,  116 => 22,  113 => 21,  110 => 20,  108 => 19,  105 => 18,  102 => 17,  99 => 16,  96 => 15,  93 => 14,  90 => 13,  87 => 12,  85 => 11,  82 => 10,  80 => 9,  77 => 8,  72 => 7,  58 => 6,  48 => 3,  45 => 2,  43 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "core/modules/navigation/templates/navigation-menu.html.twig", "/var/www/html/web/core/modules/navigation/templates/navigation-menu.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["import" => 1, "macro" => 6, "for" => 7, "set" => 9, "if" => 11, "include" => 29];
        static $filters = ["clean_class" => 25, "escape" => 28, "default" => 30, "render" => 30, "filter" => 37, "t" => 43, "lower" => 129, "first" => 129];
        static $functions = ["constant" => 13, "create_attribute" => 20, "random" => 25, "link" => 124, "icon" => 140];

        try {
            $this->sandbox->checkSecurity(
                [0 => "import", 1 => "macro", 2 => "for", 3 => "set", 4 => "if", 5 => "include"],
                [0 => "clean_class", 1 => "escape", 2 => "default", 3 => "render", 4 => "filter", 5 => "t", 6 => "lower", 7 => "first"],
                [0 => "constant", 1 => "create_attribute", 2 => "random", 3 => "link", 4 => "icon"],
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
