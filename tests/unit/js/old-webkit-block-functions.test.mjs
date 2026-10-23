// JavaScriptCore before 268553@main (September 2023, so Safari/iOS up to 17.x)
// did not implement Annex B block-level function hoisting for GLOBAL code: a
// function declared inside a block at the top of a classic script was created
// in the global scope instead of the block's, so it could not see the block's
// own let/const/class bindings. `{ let a = 42; function f() { return a } f() }`
// throws "ReferenceError: Can't find variable: a" there and works everywhere
// else (WebKit bugs 179698 and 163209, fixed in 268553@main).
//
// script.js runs its whole runtime inside `if (!_fazStore) {…} else {…}`, and
// its functions read `ref`, `currentCookieMap`, `_fazHasConsentCookie`… which
// were `const`s of that block. On iOS 16 every one of them threw, _fazInit()
// caught the error and the banner was never built: no banner, no way to
// consent (wp.org support topic "Invisible on old iOS").
//
// This test walks every frontend classic script and fails when a function
// declared in a top-level block reads a let/const/class binding that lives in
// a top-level block. It is a static rule because no engine the suite runs on
// still has the bug: Node, jsdom and every Playwright browser resolve the
// binding correctly, which is why nothing caught it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as acorn from 'acorn';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

const SKIP_KEYS = new Set(['type', 'start', 'end', 'loc', 'range', 'raw']);

function children(node) {
    const out = [];
    for (const key of Object.keys(node)) {
        if (SKIP_KEYS.has(key)) continue;
        const value = node[key];
        if (Array.isArray(value)) value.forEach((v) => { if (v && typeof v.type === 'string') out.push([key, v]); });
        else if (value && typeof value.type === 'string') out.push([key, value]);
    }
    return out;
}

function patternNames(pattern, into) {
    if (!pattern) return into;
    switch (pattern.type) {
        case 'Identifier': into.push(pattern.name); break;
        case 'ObjectPattern': pattern.properties.forEach((p) => patternNames(p.type === 'RestElement' ? p.argument : p.value, into)); break;
        case 'ArrayPattern': pattern.elements.forEach((e) => patternNames(e, into)); break;
        case 'RestElement': patternNames(pattern.argument, into); break;
        case 'AssignmentPattern': patternNames(pattern.left, into); break;
    }
    return into;
}

const isFunction = (n) => n.type === 'FunctionDeclaration' || n.type === 'FunctionExpression' || n.type === 'ArrowFunctionExpression';
const opensBlockScope = (n) => n.type === 'BlockStatement' || n.type === 'ForStatement' || n.type === 'ForInStatement'
    || n.type === 'ForOfStatement' || n.type === 'SwitchStatement' || n.type === 'CatchClause' || n.type === 'StaticBlock';

// Returns [{ fn, name, line }] for every read that old JavaScriptCore cannot resolve.
export function findOldWebKitBlockFunctionReads(source) {
    const ast = acorn.parse(source, { ecmaVersion: 'latest', sourceType: 'script', locations: true });
    const scopes = new Map(); // node -> { names:Set, lexicalAtTop:bool, parent }

    // Pass 1: build scopes and their declarations.
    function declare(scope, name) { scope.names.add(name); }
    function nearestVarScope(scope) { while (scope.kind !== 'function' && scope.kind !== 'program') scope = scope.parent; return scope; }
    function build(node, scope, inFunction) {
        let current = scope;
        if (node.type === 'Program') {
            current = { kind: 'program', names: new Set(), topLevel: true, parent: null };
            scopes.set(node, current);
        } else if (isFunction(node)) {
            current = { kind: 'function', names: new Set(), topLevel: false, parent: scope };
            scopes.set(node, current);
            node.params.forEach((p) => patternNames(p, []).forEach((n) => declare(current, n)));
            if (node.type === 'FunctionExpression' && node.id) declare(current, node.id.name);
            inFunction = true;
        } else if (opensBlockScope(node) && !(node.type === 'BlockStatement' && scopes.has(node))) {
            current = { kind: 'block', names: new Set(), topLevel: !inFunction, parent: scope };
            scopes.set(node, current);
            if (node.type === 'CatchClause' && node.param) patternNames(node.param, []).forEach((n) => declare(current, n));
        }
        if (node.type === 'FunctionDeclaration') {
            // A function declared in a top-level block is a global in every
            // engine (Annex B var binding in the new ones, the only binding in
            // the old ones), so one such function calling another is fine.
            declare(scope.kind === 'block' && scope.topLevel ? nearestVarScope(scope) : scope, node.id.name);
        } else if (node.type === 'ClassDeclaration') {
            declare(scope, node.id.name);
        } else if (node.type === 'VariableDeclaration') {
            const target = node.kind === 'var' ? nearestVarScope(scope) : scope;
            node.declarations.forEach((d) => patternNames(d.id, []).forEach((n) => declare(target, n)));
        }
        for (const [key, child] of children(node)) {
            // A function's body block is the function scope itself.
            if (isFunction(node) && key === 'body' && child.type === 'BlockStatement') {
                scopes.set(child, current);
                for (const [, grand] of children(child)) build(grand, current, true);
                continue;
            }
            build(child, current, inFunction);
        }
    }
    build(ast, null, false);

    // Pass 2: resolve every identifier read made inside a block-level function
    // declaration of global code.
    const found = [];
    function resolve(scope, name) {
        while (scope) { if (scope.names.has(name)) return scope; scope = scope.parent; }
        return null;
    }
    function isReference(parent, key, node) {
        if (!parent) return true;
        if ((parent.type === 'MemberExpression') && key === 'property' && !parent.computed) return false;
        if ((parent.type === 'Property' || parent.type === 'MethodDefinition' || parent.type === 'PropertyDefinition') && key === 'key' && !parent.computed) return false;
        if ((parent.type === 'LabeledStatement' || parent.type === 'BreakStatement' || parent.type === 'ContinueStatement') && key === 'label') return false;
        if (parent.type === 'MetaProperty') return false;
        return true;
    }
    function check(node, scope, parent, key, misScoped) {
        const own = scopes.get(node);
        if (own && own !== scope) {
            // Entering a function declared directly in a top-level block: that
            // is the function old JavaScriptCore created in the global scope.
            if (node.type === 'FunctionDeclaration' && !misScoped && scope.kind === 'block' && scope.topLevel) {
                misScoped = { fn: node.id.name, outer: scope };
            }
            scope = own;
        }
        if (node.type === 'Identifier' && misScoped && isReference(parent, key, node)) {
            const at = resolve(scope, node.name);
            if (at && at.kind === 'block' && at.topLevel && isOutside(at, misScoped.outer)) {
                found.push({ fn: misScoped.fn, name: node.name, line: node.loc.start.line });
            }
        }
        for (const [k, child] of children(node)) check(child, scope, node, k, misScoped);
    }
    // `at` is one of the scopes the mis-scoped function closes over (its
    // declaring block or one enclosing it), not a scope inside the function.
    function isOutside(at, outer) {
        for (let s = outer; s; s = s.parent) if (s === at) return true;
        return false;
    }
    check(ast, scopes.get(ast), null, null, null);
    return found;
}

const files = readdirSync(join(root, 'frontend', 'js')).filter((f) => f.endsWith('.js'));

test('the rule recognises the engine bug it guards against', () => {
    const reads = findOldWebKitBlockFunctionReads('{ let a = 42; function f() { return a } f() }');
    assert.deepEqual(reads.map((r) => `${r.fn}:${r.name}`), ['f:a']);
    // Global lexical bindings, function expressions and function-scoped code are fine.
    assert.deepEqual(findOldWebKitBlockFunctionReads('let a = 1; { function f() { return a } }'), []);
    assert.deepEqual(findOldWebKitBlockFunctionReads('{ let a = 1; var g = function () { return a }; }'), []);
    assert.deepEqual(findOldWebKitBlockFunctionReads('(function () { { let a = 1; function f() { return a } } })()'), []);
    assert.deepEqual(findOldWebKitBlockFunctionReads('{ function f() { let a = 1; return a } }'), []);
});

for (const file of files) {
    test(`${file}: no block-level function reads a block-scoped binding (Safari < 17)`, () => {
        const reads = findOldWebKitBlockFunctionReads(readFileSync(join(root, 'frontend', 'js', file), 'utf8'));
        const names = [...new Set(reads.map((r) => r.name))].sort();
        assert.deepEqual(names, [], `${file}: functions declared inside a top-level block read ${names.join(', ')}`
            + ` (first: ${reads[0] && `${reads[0].fn}() line ${reads[0].line}`}). Old Safari cannot resolve these.`);
    });
}
