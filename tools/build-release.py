#!/usr/bin/env python3
"""Gera ZIP e manifesto para publicação atômica no branch main do GitHub."""
import argparse
import hashlib
import json
import re
import zipfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REPO = 'https://github.com/paulommaeda/push-notifications'
RAW = 'https://raw.githubusercontent.com/paulommaeda/push-notifications/main/'

def field(text, name):
    match = re.search(r'^\s*(?:\*\s*)?' + re.escape(name) + r':\s*(.+?)\s*$', text, re.M)
    if not match:
        raise ValueError('Campo obrigatório ausente: ' + name)
    return match.group(1)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--allow-rebuild', action='store_true', help='Somente para uma versão que ainda não foi publicada.')
    args = parser.parse_args()
    main_file = (ROOT / 'portal-push.php').read_text()
    readme = (ROOT / 'readme.txt').read_text()
    version = field(main_file, 'Version')
    if not re.fullmatch(r'\d{1,5}\.\d{1,5}\.\d{1,5}', version):
        raise ValueError('A versão deve ser estável, no formato 0.2.0.')
    if field(main_file, 'Update URI') != REPO or field(readme, 'Stable tag') != version:
        raise ValueError('Confira Update URI e Stable tag.')
    if not re.search(r"define\('PPUSH_VERSION',\s*'" + re.escape(version) + r"'\)", main_file):
        raise ValueError('PPUSH_VERSION não corresponde ao cabeçalho.')
    if not (ROOT / 'vendor/autoload.php').is_file():
        raise ValueError('O pacote deve conter as dependências isoladas em vendor/.')
    dist = ROOT / 'dist'
    updates = ROOT / 'updates'
    dist.mkdir(exist_ok=True)
    updates.mkdir(exist_ok=True)
    output = dist / ('portal-push-' + version + '.zip')
    temporary = dist / ('.portal-push-' + version + '.tmp')
    excluded = {'.git', '.github', 'dist', 'updates', 'tools', 'tests', '__pycache__', '.venv'}
    with zipfile.ZipFile(temporary, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(ROOT.rglob('*')):
            relative = path.relative_to(ROOT)
            if any(part in excluded for part in relative.parts):
                continue
            if path.is_symlink():
                raise ValueError('Link simbólico não permitido: ' + str(relative))
            if not path.is_file() or path.name in {'.gitignore', '.gitattributes', '.DS_Store'}:
                continue
            if path.name in {'.env', 'wp-config.php'} or 'fixture' in path.name or path.suffix == '.log':
                raise ValueError('Arquivo não distribuível: ' + str(relative))
            info = zipfile.ZipInfo('portal-push/' + relative.as_posix(), (2020, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes(), compresslevel=9)
    if output.exists() and output.read_bytes() != temporary.read_bytes() and not args.allow_rebuild:
        temporary.unlink()
        raise ValueError('O ZIP desta versão já existe. Aumente a versão para publicar uma alteração.')
    temporary.replace(output)
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:
            raise ValueError('ZIP corrompido.')
        for path in ['portal-push.php', 'vendor/autoload.php', 'includes/class-updater.php']:
            archive.getinfo('portal-push/' + path)
    metadata = {
        'schema': 1, 'name': 'Portal Push — Pessoas e Perfis', 'slug': 'portal-push', 'version': version,
        'homepage': REPO, 'requires': field(main_file, 'Requires at least'),
        'requires_php': field(main_file, 'Requires PHP'), 'tested': field(readme, 'Tested up to'),
        'download_url': RAW + 'dist/' + output.name,
        'sha256': hashlib.sha256(output.read_bytes()).hexdigest(),
        'last_updated': datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S'),
        'sections': {
            'description': '<p>Notificações Web Push com núcleo próprio, cadastro de pessoas, múltiplos perfis e envio segmentado para portais WordPress.</p>',
            'installation': '<p>Instale o ZIP pelo painel, gere as chaves de envio e crie a página com o shortcode [portal_push]. Cada pessoa autoriza o recebimento no próprio dispositivo.</p>',
            'changelog': '<h4>' + version + '</h4><p>Atualizações nativas pelo WordPress a partir do GitHub, instalação automática opcional, verificação SHA-256 do pacote e preservação do diretório do plugin.</p>',
        },
    }
    (updates / 'portal-push.json').write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'version': version, 'zip': str(output), 'bytes': output.stat().st_size, 'sha256': metadata['sha256']}, ensure_ascii=False))

if __name__ == '__main__':
    main()
