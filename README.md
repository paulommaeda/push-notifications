# Portal Push — Pessoas e Perfis

Notificações **push na área de avisos do navegador ou do smartphone**, inclusive com o PWA instalado. O toque na notificação abre o link escolhido no portal. A página opcional serve para ativar, testar e pausar dispositivos; não é uma caixa de mensagens.

Os destinatários são os **usuários do WordPress**. Os perfis são seus **papéis**, como Assinante e Editor, incluindo os personalizados já existentes no portal. O plugin não cria grupos próprios de destinatários nem altera os papéis ao enviar uma campanha.

## Instalar a versão 0.4.0

[Baixar o ZIP instalável](https://raw.githubusercontent.com/paulommaeda/push-notifications/main/dist/portal-push-0.4.0.zip)

No WordPress: **Plugins → Adicionar plugin → Enviar plugin**, selecione o ZIP e ative. Para uma instalação existente, escolha substituir a versão atual. A partir da versão 0.2.0, também é possível receber a atualização pelo painel.

Use `dist/portal-push-0.4.0.zip`, que contém a pasta `portal-push/` e as dependências prontas. O ZIP do botão Code do GitHub é o repositório de desenvolvimento, não o pacote de instalação.

## Como funciona

1. A administração gera as chaves em **Portal Push → Configurações**.
2. Cada pessoa entra com sua conta WordPress e clica em **Ativar notificações** no próprio dispositivo. O botão aparece nas páginas do portal para usuários conectados.
3. A administração escolhe pessoas específicas, um ou mais papéis do WordPress ou todos os habilitados e cria a campanha.
4. O servidor processa a fila, assina e criptografa a mensagem. O navegador/sistema exibe o aviso, inclusive quando a página não está aberta, conforme as permissões e condições do dispositivo.

O núcleo, as chaves VAPID, as campanhas, a fila e os relatórios ficam na hospedagem do WordPress. Não é necessário contratar uma plataforma de notificações nem configurar um projeto Firebase. A entrega Web Push utiliza os serviços dos próprios navegadores/sistemas, como Google, Mozilla e Apple.

## Recursos

- Usuários e papéis padrão ou personalizados lidos diretamente do WordPress.
- Cadastro e alteração de contas pelas telas nativas de Usuários.
- Até 50 dispositivos ativos por pessoa, com autorização individual.
- Campanhas individuais, por papéis combinados por OU ou para todos os habilitados.
- Agendamento, cancelamento, novas tentativas e relatórios por dispositivo.
- Botão de ativação nas páginas e gerenciamento opcional com `[portal_push]`.
- Integração do push ao worker de **PWA for WP** e **SuperPWA**.
- Modelos editáveis com variáveis, chave de integração e destinatários padrão.
- API `portal_push_notify()` e gancho para eventos de outros plugins, com prevenção de duplicidade por evento.
- API PHP `portal_push_send()` preservada para mensagens sem modelo.
- Atualização pelo painel do WordPress e instalação automática opcional.

Requer WordPress 6.4+, PHP 8.2+, HTTPS, OpenSSL, cURL e mbstring. O ZIP inclui as dependências com namespaces isolados.

## PWA e celulares

PWA for WP e SuperPWA continuam responsáveis pelo aplicativo, manifesto e navegação. O Portal Push acrescenta os eventos de notificação ao worker deles. Depois da atualização, abra o painel uma vez. Ao clicar em **Ativar notificações**, o cliente aguarda a instalação do novo componente e solicita sua ativação somente se ele confirmar a integração com o Portal Push.

Se aparecer o aviso de integração pendente, use **Portal Push → Configurações → Reparar integração com o PWA**. O botão regenera e verifica o arquivo/template no servidor e renova a URL de registro do SuperPWA. Se houver cache/CDN na hospedagem, limpe o cache dos arquivos do PWA; reabra o aplicativo e tente a ativação. O diagnóstico do painel confirma o arquivo local, não o estado do celular ou de uma CDN.

Outros PWAs exigem integrar o módulo conforme [LEIA-ME.md](LEIA-ME.md). Um worker sem integração não é substituído. No iPhone/iPad, use iOS/iPadOS 16.4+ e abra o portal pelo ícone adicionado à Tela de Início antes de autorizar os avisos.

## Disparar a partir de outro plugin

Em **Portal Push → Modelos e integrações**, crie a chave `tarefa_concluida`, o título, a mensagem com variáveis e o público padrão. No evento do outro plugin, chame:

```php
$campaign_id = portal_push_notify('tarefa_concluida', [
    'user_id' => 123,
    'variables' => ['nome' => 'Ana', 'tarefa' => 'Revisar comunicado'],
    'url' => home_url('/tarefas/'),
    'event_id' => 'tarefa:456:concluida',
]);
```

A função retorna o ID da campanha ou `WP_Error`. O evento também pode usar `do_action('portal_push_notify', $key, $context)`. A integração acontece dentro do mesmo WordPress; o outro plugin valida a ação e informa o destinatário. [Veja o guia completo e os exemplos de ganchos](INTEGRACOES.md).

## Atualizações pelo painel

O WordPress consulta `updates/portal-push.json` neste repositório e baixa o pacote versionado de `dist/`. O plugin verifica seu SHA-256 antes da instalação.

- **Atualizar com um clique:** use **Atualizar agora** na tela Plugins quando houver versão nova.
- **Instalar automaticamente:** ative a opção em **Portal Push → Configurações → Atualizações pelo WordPress** ou na tela Plugins.
- **Consultar imediatamente:** clique em **Verificar atualizações agora** nas configurações.

As consultas válidas ficam em cache por seis horas; falhas, por quinze minutos. O processo usa o mecanismo nativo do WordPress e respeita o agendador, as permissões e as políticas da hospedagem. Não requer token GitHub porque o repositório é público.

Na migração para 0.3.0, contas, papéis, inscrições e chaves são preservados. **Campanhas pendentes dos grupos próprios das versões antigas são canceladas**: crie novos envios com os papéis do WordPress. As campanhas individuais continuam válidas. O antigo papel `portal_push_member`, quando já existir, é mantido para preservar o acesso das contas; novas instalações não o criam.

## Publicar a próxima versão

1. Altere `Version` e `PPUSH_VERSION` em `portal-push.php` e `Stable tag` em `readme.txt`.
2. Atualize o histórico em `readme.txt` e a documentação. Preserve o isolamento das dependências em `PortalPushVendor`.
3. Execute `python3 tools/build-release.py` para gerar o pacote e o manifesto com o histórico da versão.
4. Teste código, atualização e pacote em homologação.
5. Publique código, ZIP novo em `dist/` e manifesto em `updates/` no mesmo commit de `main`.

Cada pacote publicado deve permanecer imutável. Aumente a versão para corrigir um pacote já publicado. `--allow-rebuild` só deve ser usado durante a preparação local de uma versão ainda não publicada. Não execute Composer sobre o `vendor/` isolado sem repetir o processo de isolamento.

## Documentação

- [Instalação, PWA e integração PHP](LEIA-ME.md)
- [API de modelos e integração com outros plugins](INTEGRACOES.md)
- [Testes e limites conhecidos](VALIDACAO.md)
- [Histórico](readme.txt)
- [Licença GPL](LICENSE.txt)

Criptografia local: minishlink/web-push 10.1.0 (MIT). As licenças das dependências acompanham seus diretórios em `vendor/`.
