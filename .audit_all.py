import pathlib
import re

root = pathlib.Path('.')
declared = set(re.findall(r"'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'", (root / 'database/seeders/FoundationPermissionSeeder.php').read_text()))
route_names = set(re.findall(r"->name\('([^']+)'\)", (root / 'routes/web.php').read_text()))
css = (root / 'resources/css/app.css').read_text()
css_classes = set(re.findall(r'\.(erp-[a-z0-9-]+)', css))
components = {p.name.replace('.blade.php', '') for p in (root / 'resources/views/components/ui').glob('*.blade.php')}
js_hooks = set(re.findall(r'data-erp-([a-z-]+)', (root / 'resources/js/app.js').read_text()))

bad = 0
for v in sorted((root / 'resources/views/purchase').rglob('*.blade.php')):
    s = v.read_text()
    out = []
    for name in re.findall(r"route\('([^']+)'", s):
        if name not in route_names:
            out.append('route: ' + name)
    for key in re.findall(r"\$perm\('([^']+)'\)", s):
        if key not in declared:
            out.append('perm: ' + key)
    for cls in set(re.findall(r'class="([^"]*)"', s)):
        for token in cls.split():
            if token.startswith('erp-') and token not in css_classes and not token.startswith('erp-status-'):
                out.append('css: ' + token)
    for comp in re.findall(r'<x-ui\.([a-z0-9-]+)', s):
        if comp not in components:
            out.append('component: ' + comp)
    for hook in set(re.findall(r'data-erp-([a-z-]+)', s)):
        if hook not in js_hooks:
            out.append('hook: ' + hook)
    if out:
        bad += 1
        print(v, sorted(set(out)))
print('purchase views with findings:', bad)
