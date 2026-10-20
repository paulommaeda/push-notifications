# Portal Push — Pessoas e Perfis

Plugin WordPress de Paulo Maeda para notificações Web Push em portais com pessoas autenticadas. O cadastro, os perfis, as chaves VAPID, a fila e os relatórios ficam na hospedagem do WordPress.

## Instalar a versão 0.2.0

[Baixar o ZIP instalável](https://raw.githubusercontent.com/paulommaeda/push-notifications/main/dist/portal-push-0.2.0.zip)

No WordPress, abra **Plugins → Adicionar plugin → Enviar plugin**, selecione esse ZIP e ative. Se a versão 0.1.0 já estiver instalada, envie o ZIP e escolha a opção para substituir a versão atual. Essa instalação manual inicial acrescenta o atualizador às instalações antigas.

O pacote é `dist/portal-push-0.2.0.zip`, com a pasta interna `portal-push/` e todas as dependências incluídas. Para instalar, prefira esse arquivo ao ZIP de código do botão Code do GitHub.

## Recursos

- Contas WordPress existentes e cadastro de novas pessoas.
- Vários perfis de destinatários por pessoa, independentes dos papéis de acesso.
- Inscrição de vários dispositivos, com autorização pelo navegador.
- Campanhas individuais, por um ou mais perfis ou para todos os habilitados.
- Agendamento, cancelamento, tentativas de reenvio e relatórios por dispositivo.
- Página de ativação/pausa com `[portal_push]` e API PHP `portal_push_send()`.
- Atualizações pelo painel do WordPress e instalação automática opcional.

## Configurar o portal

1. Abra **Portal Push → Configurações** e gere as chaves no servidor.
2. Crie a página de notificações pelo botão ou insira `[portal_push]` em uma página.
3. Cadastre os perfis e associe as pessoas.
4. Cada pessoa entra no portal e ativa os avisos no próprio dispositivo.
5. Crie uma campanha e selecione as pessoas ou perfis destinatários.

Requer WordPress 6.4+, PHP 8.2+, HTTPS e extensões OpenSSL, cURL e mbstring. As dependências estão incluídas em `vendor/`. Os serviços Web Push dos navegadores continuam responsáveis pela entrega aos dispositivos.

## Atualizações pelo painel

O WordPress consulta `updates/portal-push.json`, publicado neste repositório, e baixa o ZIP versionado de `dist/`. O plugin verifica o SHA-256 antes de permitir a instalação.

- **Atualizar com um clique:** na tela **Plugins**, use **Atualizar agora** quando houver uma versão nova.
- **Instalar automaticamente:** em **Portal Push → Configurações → Atualizações pelo WordPress**, habilite a opção e salve. A mesma preferência está na coluna de atualizações automáticas da tela Plugins.
- **Consultar imediatamente:** use **Verificar atualizações agora** nas configurações do plugin.

As consultas bem-sucedidas são armazenadas por seis horas; falhas temporárias, por quinze minutos. A instalação automática usa WP-Cron e respeita as permissões, os bloqueios de alterações de arquivos e as políticas da hospedagem.

As pessoas, os perfis, as inscrições e as chaves são mantidos no banco durante a atualização. O código de atualização é carregado mesmo se o restante do plugin estiver com dependências ausentes.

## Publicar a próxima versão

1. Altere `Version` e `PPUSH_VERSION` em `portal-push.php` e `Stable tag` em `readme.txt`.
2. Atualize documentação e histórico. Se houver mudança de dependências, mantenha o isolamento em `PortalPushVendor`; não substitua o `vendor/` pronto por uma instalação Composer sem o isolamento.
3. Gere o pacote e o manifesto:

```bash
python3 tools/build-release.py
```

4. Teste o plugin e o pacote em homologação.
5. Publique o código, o novo ZIP em `dist/` e o manifesto em `updates/` juntos no mesmo commit de `main`.

Cada versão publicada deve permanecer imutável. Para corrigir uma versão, aumente seu número e gere outro pacote. O script recusa sobrescrever um ZIP diferente com o mesmo número; `--allow-rebuild` serve apenas para preparação local de uma versão ainda não publicada. O comando gera arquivos locais; a publicação ocorre pelo Git.

Não é necessário token GitHub no WordPress porque este repositório é público.

## Documentação

- [Guia de instalação e integração](LEIA-ME.md)
- [Validação e limites conhecidos](VALIDACAO.md)
- [Histórico](readme.txt)
- [Licença GPL](LICENSE.txt)

Criptografia Web Push: minishlink/web-push 10.1.0 (MIT). As licenças das demais dependências acompanham os respectivos diretórios em `vendor/`.
