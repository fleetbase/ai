#!/usr/bin/env node
/**
 * Verifies Fleetbase AI console commands against the console and engine source code.
 *
 * Every `navigate` step must name a route that exists, and every `service` step must name a service
 * file and a method that exists in that engine. Run it from a Fleetbase workspace where the console
 * and engine packages are checked out side by side:
 *
 *   node scripts/verify-ai-commands.mjs --console ../../console --packages .. \
 *     [--commands path/to/autoload.php:Fully\\Qualified\\Class]...
 *
 * Commands are read by calling the PHP class's static all() method. The AI package's core commands are
 * always included. Engines that are not checked out are reported as skipped rather than failed.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const option = (name, fallback = null) => {
    const index = args.indexOf(`--${name}`);
    return index >= 0 ? args[index + 1] : fallback;
};
const options = (name) => args.flatMap((arg, index) => (arg === `--${name}` ? [args[index + 1]] : []));

const consoleDir = resolve(option('console', join(root, '../../console')));
const packagesDir = resolve(option('packages', join(root, '..')));

// Methods every ResourceActionService inherits.
const INHERITED_METHODS = new Set(['create', 'update', 'delete', 'bulkDelete', 'export', 'import', 'search', 'refresh', 'transitionTo']);

/**
 * Collect full route names from an Ember router map function. Routes with a callback also get an
 * implicit `index` child, matching Ember's behavior.
 */
function collectRoutes(source, prefix, mapPattern) {
    const match = source.match(mapPattern);
    if (!match) {
        throw new Error('Could not find the route map.');
    }

    const routes = new Set(prefix ? [prefix] : []);
    const dsl = (parent) => ({
        route(name, maybeOptions, maybeCallback) {
            const callback = typeof maybeOptions === 'function' ? maybeOptions : maybeCallback;
            const full = parent ? `${parent}.${name}` : name;
            routes.add(full);
            if (callback) {
                routes.add(`${full}.index`);
                callback.call(dsl(full));
            }
        },
        mount() {},
    });

    const map = new Function(`return ${match[1]}`)();
    map.call(dsl(prefix));

    return routes;
}

function loadEngines() {
    const engines = new Map();

    for (const name of readdirSync(packagesDir)) {
        const dir = join(packagesDir, name);
        const packageJson = join(dir, 'package.json');
        const routesFile = join(dir, 'addon/routes.js');
        const environment = join(dir, 'config/environment.js');

        if (!statSync(dir).isDirectory() || !existsSync(packageJson) || !existsSync(routesFile) || !existsSync(environment)) {
            continue;
        }

        const pkg = JSON.parse(readFileSync(packageJson, 'utf8'));
        const mount = pkg.fleetbase?.route ?? readFileSync(environment, 'utf8').match(/let mountedEngineRoutePrefix = '([^']+)'/)?.[1];
        if (!mount || engines.has(pkg.name)) {
            continue;
        }

        const routes = collectRoutes(readFileSync(routesFile, 'utf8'), `console.${mount}`, /buildRoutes\((function \(\) \{[\s\S]*\})\);?\s*$/);
        engines.set(pkg.name, { dir, mount, routes });
    }

    return engines;
}

function loadCommands() {
    const sources = [`${join(root, 'server_vendor/autoload.php')}:Fleetbase\\Ai\\Support\\Commands\\CoreConsoleCommands`, ...options('commands')];

    return sources.flatMap((source) => {
        const separator = source.lastIndexOf(':');
        const [autoload, className] = [source.slice(0, separator), source.slice(separator + 1)];
        const php = `require ${JSON.stringify(resolve(autoload))}; echo json_encode(array_map(fn ($c) => is_array($c) ? $c : ['id' => $c->id, 'steps' => $c->steps], ${className}::all()));`;

        // Diagnostics from anything the autoload pulls in would otherwise land on stdout and
        // corrupt the JSON, so silence them for this one-off process.
        const args = ['-d', 'error_reporting=0', '-d', 'display_errors=0', '-r', php];

        return JSON.parse(execFileSync('php', args, { encoding: 'utf8' }));
    });
}

const consoleRoutes = collectRoutes(readFileSync(join(consoleDir, 'app/router.js'), 'utf8'), '', /Router\.map\((function \(\) \{[\s\S]*\})\);?\s*$/);
const engines = loadEngines();
const commands = loadCommands();
const failures = [];
const skipped = new Set();

for (const command of commands) {
    for (const step of command.steps) {
        if (step.type === 'navigate') {
            const engine = [...engines.values()].find((candidate) => step.route.startsWith(`console.${candidate.mount}.`));
            if (engine) {
                if (!engine.routes.has(step.route)) {
                    failures.push(`${command.id}: route ${step.route} does not exist`);
                }
            } else if (consoleRoutes.has(step.route)) {
                continue;
            } else if (/^console\.(admin|settings|account|home|notifications)(\.|$)/.test(step.route)) {
                failures.push(`${command.id}: console route ${step.route} does not exist`);
            } else {
                skipped.add(step.route.split('.').slice(0, 2).join('.'));
            }
        } else if (step.type === 'service') {
            const engine = engines.get(step.engine);
            if (!engine) {
                skipped.add(step.engine);
                continue;
            }

            const serviceFile = join(engine.dir, 'addon/services', `${step.service}.js`);
            if (!existsSync(serviceFile)) {
                failures.push(`${command.id}: service ${step.engine}/${step.service} does not exist`);
                continue;
            }

            const source = readFileSync(serviceFile, 'utf8');
            const [first, second] = step.method.split('.');
            const found = second ? new RegExp(`${first}\\s*=\\s*\\{[\\s\\S]*?\\b${second}\\s*:`).test(source) : new RegExp(`\\b${first}\\s*\\(`).test(source) || INHERITED_METHODS.has(first);

            if (!found) {
                failures.push(`${command.id}: ${step.engine}/${step.service} has no ${step.method}`);
            }
        } else {
            failures.push(`${command.id}: unsupported step type ${step.type}`);
        }
    }
}

console.log(`Checked ${commands.length} AI console commands against ${engines.size} engines and the console router.`);
if (skipped.size) {
    console.log(`Skipped (not checked out): ${[...skipped].join(', ')}`);
}
if (failures.length) {
    console.error(failures.map((failure) => `  ✗ ${failure}`).join('\n'));
    process.exit(1);
}
console.log('All AI console commands resolve.');
