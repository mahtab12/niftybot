import { context } from 'esbuild';
import fg from 'fast-glob';
import dotenv from 'dotenv';
import bs from 'browser-sync';
import { watch as fsWatch, rmSync, existsSync } from 'node:fs';
import {
  makeSassPlugin,
  stylelintPlugin,
  copyPlugin,
  bootstrapIconsPlugin,
  componentJsEntries,
} from './plugins.mjs';

dotenv.config({ path: '.env.local' });

// Inside a DDEV web container the site is served on localhost; on the host the
// configured DRUPAL_BASE_URL is used.
const isDdev = process.env.IS_DDEV_PROJECT?.toLowerCase() === 'true';
const proxy = isDdev ? 'localhost' : process.env.DRUPAL_BASE_URL;

const browserSync = bs.create();

browserSync.init({
  proxy,
  open: !isDdev,
  notify: false,
  files: [
    'components/**/*.css',
    'components/**/*.js',
    'components/**/*.twig',
    'templates/**/*.twig',
    'build/css/*.css',
    'build/js/*.js',
  ],
});

const reloadPlugin = {
  name: 'reload',
  setup(build) {
    build.onEnd((result) => {
      if (result.errors.length === 0) {
        browserSync.reload();
      }
    });
  }
};

// One esbuild context per component SCSS file, keyed by source path. esbuild
// only watches each entry's own dependency graph, so newly-added components are
// registered dynamically by the filesystem watcher below.
const scssContexts = new Map();

async function watchScss(file) {
  if (scssContexts.has(file)) {
    return;
  }
  const ctx = await context({
    entryPoints: [file],
    outfile: file.replace('.scss', '.css'),
    plugins: [makeSassPlugin(), reloadPlugin],
  });
  scssContexts.set(file, ctx);
  await ctx.watch();
}

async function unwatchScss(file) {
  const ctx = scssContexts.get(file);
  if (!ctx) {
    return;
  }
  await ctx.dispose();
  scssContexts.delete(file);
  // Remove the now-orphaned compiled output.
  for (const out of [file.replace('.scss', '.css'), file.replace('.scss', '.css.map')]) {
    rmSync(out, { force: true });
  }
}

// Component JS is bundled through a single context with an entryPoints map.
// entryPoints can't be mutated, so the context is rebuilt when the set of
// _*.js sources changes.
let jsContext = null;
// Sources covered by the last successful build. Tracked independently of the
// context lifecycle so a fast-path dispose on deletion doesn't lose the record
// needed to clean up orphaned output.
let jsBuiltSources = new Set();

async function disposeJsContext() {
  if (jsContext) {
    const ctx = jsContext;
    jsContext = null;
    await ctx.dispose();
  }
}

async function rebuildJsContext() {
  await disposeJsContext();
  const entries = componentJsEntries();
  const sources = new Set(Object.values(entries));
  // Remove compiled output for component JS sources that no longer exist.
  for (const source of jsBuiltSources) {
    if (!sources.has(source)) {
      const out = source.replace(/\/_([^/]+)\.js$/, '/$1.js');
      rmSync(out, { force: true });
      rmSync(`${out}.map`, { force: true });
    }
  }
  jsBuiltSources = sources;
  if (sources.size === 0) {
    return;
  }
  jsContext = await context({
    entryPoints: entries,
    outdir: '.',
    bundle: true,
    minify: false,
    sourcemap: true,
    external: ['/themes/*'],
    plugins: [reloadPlugin],
  });
  await jsContext.watch();
}

// Initial build: register every existing component SCSS and build component JS.
for (const file of fg.sync('components/**/*.scss')) {
  await watchScss(file);
}
await rebuildJsContext();

// Watch the global theme bundle, plus assets and icons.
const globalCtx = await context({
  entryPoints: {
    'css/main.style': 'src/scss/main.style.scss',
    'js/main.script': 'src/js/main.script.js',
  },
  bundle: true,
  sourcemap: true,
  minify: false,
  outdir: 'build',
  external: ['/themes/*'],
  plugins: [
    makeSassPlugin(),
    reloadPlugin,
    stylelintPlugin,
    copyPlugin(true),
    bootstrapIconsPlugin
  ]
})

await globalCtx.watch()

// Pick up components added or removed while watch is running. esbuild's own
// watcher only tracks known entries (so it handles edits to existing files),
// while this recursive fs watcher registers new component contexts and tears
// down removed ones.
const debounce = new Map();

function reconcileScss() {
  const current = new Set(fg.sync('components/**/*.scss'));
  for (const file of current) {
    if (!scssContexts.has(file)) {
      watchScss(file);
    }
  }
}

const isComponentJs = (p) => /(^|[/\\])_[^/\\]+\.js$/.test(p) && !p.includes('node_modules');

if (existsSync('components')) {
  fsWatch('components', { recursive: true }, (_event, filename) => {
    if (!filename) {
      return;
    }
    // fs.watch reports paths relative to the watched dir; key by the same
    // project-relative path the contexts use.
    const rel = `components/${filename.toString().split('\\').join('/')}`;

    // Removals are handled immediately (no debounce) so the context is disposed
    // before esbuild's watcher reports a transient resolve error for the gone
    // file.
    if (!existsSync(rel)) {
      if (scssContexts.has(rel)) {
        unwatchScss(rel);
        return;
      }
      if (jsBuiltSources.has(rel)) {
        // Tear down now; the debounced rebuild below re-creates it without the
        // removed entry and cleans up its output.
        disposeJsContext();
      }
    }

    if (!rel.endsWith('.scss') && !isComponentJs(rel)) {
      return;
    }

    // Debounce additions/edits: editors fire several events per save.
    clearTimeout(debounce.get(rel));
    debounce.set(rel, setTimeout(() => {
      debounce.delete(rel);
      if (rel.endsWith('.scss')) {
        reconcileScss();
      } else if (isComponentJs(rel)) {
        rebuildJsContext();
      }
    }, 100));
  });
}
