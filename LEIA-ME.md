# Portal Push — Guia da versão 0.4.0

## Onde o aviso aparece

A notificação aparece na área de notificações do navegador ou do sistema do celular, inclusive quando o portal está instalado como PWA. A pessoa toca no aviso para abrir o link indicado pela administração. Ela não precisa manter uma página de notificações aberta. A exibição depende da autorização, da conexão e das configurações do navegador/sistema.

A página opcional com `[portal_push]` serve para autorizar, enviar um teste e pausar dispositivos. Ela não guarda uma caixa de mensagens lidas/não lidas.

## Pessoas e perfis

| Escolha do envio | Destinatários |
|---|---|
| Pessoas específicas | Contas selecionadas do WordPress, pelo ID do usuário. |
| Perfis do WordPress | Pessoas que possuem um dos papéis selecionados: Assinante, Editor ou papéis personalizados já registrados no portal. |
| Todas as pessoas habilitadas | Usuários com recebimento permitido e dispositivos ativos. |

O cadastro e a alteração de papéis acontecem em **Usuários** no WordPress. A seleção de um perfil para envio não concede acesso nem altera a conta. Não há outro cadastro de grupos no plugin.

Papéis selecionados são combinados por **OU**. Um dispositivo recebe uma vez por campanha, mesmo se o usuário tiver vários papéis selecionados. Uma pessoa com celular e computador inscritos poderá receber em ambos.

O público é calculado quando a campanha fica disponível para envio. Antes de cada disparo, o plugin verifica novamente os papéis atuais, o recebimento habilitado e o vínculo do dispositivo. Quem perder o papel selecionado deixa de ser elegível; usuários adicionados depois da expansão da fila entram nos próximos envios.

## Instalar e configurar

1. Envie `portal-push-0.4.0.zip` por **Plugins → Adicionar plugin → Enviar plugin** e ative. Se houver versão anterior, substitua pelo painel.
2. Abra **Portal Push → Configurações**, confira o e-mail técnico e clique em **Gerar chaves no servidor**. O e-mail identifica o emissor; essa configuração não envia e-mails.
3. Confira os usuários e papéis existentes no WordPress. Para novas contas, use **Usuários → Adicionar novo**.
4. Cada pessoa entra no portal e clica em **Ativar notificações** no dispositivo em que quer receber os avisos. A solicitação de permissão aparece depois desse clique.
5. Em **Nova notificação**, escolha pessoas, perfis ou todos, preencha título, mensagem e link do portal e crie a campanha.
6. Se desejar gerenciamento detalhado, crie uma página com `[portal_push]` pelo botão das configurações ou insira o shortcode em uma página comum.

O convite nas páginas aparece somente para usuários conectados e habilitados. A pessoa pode dispensá-lo por sete dias. Nas configurações é possível desligar o convite e manter a ativação na página opcional.

Criar uma conta ou conhecer seu e-mail não autoriza o push. Cada navegador/instalação do PWA precisa da autorização da pessoa. O limite é de 50 dispositivos ativos por conta.

Ao fazer logout do WordPress, o navegador identificado é pausado; outros dispositivos continuam ativos. Entrar novamente não reativa uma inscrição pausada sem novo clique. A troca de conta no mesmo navegador requer ativação explícita e a prova local do dispositivo.

## Hospedagem

- WordPress 6.4+, PHP 8.2+, OpenSSL, cURL e mbstring.
- HTTPS no portal e conexões HTTPS de saída para os serviços Web Push dos navegadores.
- GMP ou BCMath são opcionais para melhorar o desempenho criptográfico.
- Preserve banco e `wp-config.php` nos backups, incluindo as chaves de envio armazenadas no banco.

O ZIP inclui as dependências isoladas em `PortalPushVendor`; não precisa executar Composer na hospedagem. O núcleo executa localmente a assinatura VAPID e a criptografia. Não exige conta em plataforma comercial nem projeto Firebase. A entrega ao dispositivo passa pelos serviços de push do navegador/sistema.

## PWA instalado

**PWA for WP e SuperPWA:** o Portal Push incorpora seu módulo de eventos ao worker existente pelos filtros de geração desses plugins. O aplicativo continua usando seu manifesto, cache e navegação. Ao abrir o painel depois da atualização, os arquivos de worker são regenerados e verificados. O cliente aguarda a instalação do novo componente; depois do clique em **Ativar notificações**, solicita a ativação apenas do worker que confirmou a integração. A sincronização em segundo plano não força essa ativação.

**Se aparecer “O aplicativo ainda não concluiu a atualização dos avisos”:** atualize para 0.3.1 ou posterior e use **Portal Push → Configurações → Reparar integração com o PWA**. O reparo verifica o arquivo/template atual, detecta um arquivo antigo ou ausente mesmo quando a versão já estava registrada e preserva chaves, usuários e inscrições. A URL de registro do SuperPWA recebe a versão/revisão para identificar a atualização. Se a hospedagem tiver cache/CDN, limpe o cache dos arquivos do PWA; reabra o aplicativo e clique em **Ativar notificações**. O estado “Pronta” no painel verifica o componente no servidor; o celular e uma CDN ainda podem estar usando uma versão anterior.

Antes de cadastrar o dispositivo, o cliente pede uma confirmação ao worker ativo. Se ele ainda não tiver o módulo, a inscrição é interrompida com uma orientação de atualização. O plugin não substitui um worker de outro PWA. Mantenha apenas um emissor push nesse mesmo registro; ao migrar de outro serviço, o clique explícito de ativação troca a inscrição para a chave VAPID local.

**Outros PWAs:** o desenvolvedor pode acrescentar o módulo ao JavaScript gerado do worker:

```php
$worker_javascript = \PauloMaeda\PortalPush\Pwa::inject($worker_javascript);
```

Use esse código no filtro de geração do worker do seu PWA, com o Portal Push carregado, e regenere o arquivo. O módulo contém os eventos de push, clique e confirmação de suporte. Não registra eventos de instalação/ativação nem assume o cache; atende um pedido explícito do cliente para ativar uma atualização integrada depois do clique de autorização. Ele ignora mensagens e cliques que não pertençam ao Portal Push. A integração deve ser homologada com o PWA específico do portal.

**Sem outro plugin PWA:** o Portal Push fornece um worker para notificações e um manifesto básico com abertura independente. Não acrescenta navegação offline. Configure o ícone do site no WordPress para fornecer os ícones do manifesto.

**iPhone/iPad:** o fluxo Web Push padrão requer iOS/iPadOS 16.4+, portal adicionado à Tela de Início e aberto pelo ícone. A autorização parte de um toque da pessoa. No Android e no computador, utilize um navegador com suporte a Web Push; o PWA instalado usa o mesmo módulo de notificações.

O manifesto básico é desabilitado automaticamente quando PWA for WP ou SuperPWA está ativo. Para outro PWA que já forneça um manifesto:

```php
add_filter('portal_push_disable_manifest', '__return_true');
```

Exclua do cache de páginas as telas autenticadas e os endpoints do plugin, inclusive `/wp-json/portal-push/` e a forma `?rest_route=/portal-push/`. Os nonces e dados de uma sessão não devem ser reutilizados por outra conta. Teste essas exclusões também no cache do PWA.

## Fila e relatórios

Os horários acompanham **Configurações → Geral → Fuso horário**, com armazenamento em UTC. A fila persistente roda pelo WP-Cron a cada minuto, com até aproximadamente 20 segundos de trabalho, 100 inscrições por rodada e 6 segundos de espera por requisição. O volume efetivo depende da hospedagem e da latência; não houve teste de carga.

Em portais com poucas visitas, configure a hospedagem para executar `wp-cron.php` a cada minuto. Com WP-CLI:

```bash
wp cron event run --due-now --path=/caminho/do/wordpress
```

**Processar fila agora** executa uma rodada manual. Falhas transitórias (HTTP 429/5xx) têm até três tentativas com espera crescente e respeito a `Retry-After`. Inscrições expiradas (404/410) são pausadas.

**Aceita pelo serviço** informa que o serviço do navegador aceitou a requisição. Não comprova exibição ou leitura. O relatório registra um clique por dispositivo. O sistema não garante entrega imediata ou exatamente uma vez; a identificação estável da campanha reduz duplicação visual.

## Modelos e eventos de outros plugins

A partir de 0.4.0, **Portal Push → Modelos e integrações** permite salvar notificações pré-configuradas. Cada modelo tem uma chave permanente, título, mensagem, link, variáveis como `{nome}` e `{tarefa}`, público padrão e ativação/pausa.

O outro plugin chama `portal_push_notify($key, $context)` ou emite `do_action('portal_push_notify', $key, $context)` dentro do seu evento. O destinatário pode ser um usuário WordPress, vários usuários, papéis ou o público pré-configurado. `event_id` opcional evita repetir a campanha para o mesmo modelo e público. Nenhum evento de outro plugin é interceptado automaticamente sem o código de ligação correspondente.

O retorno da função é o ID da campanha ou `WP_Error`. Variáveis ausentes, públicos inválidos e links externos são rejeitados. O conteúdo final fica salvo na campanha; editar/pausar/excluir o modelo não cancela pendências já criadas. O arquivo [INTEGRACOES.md](INTEGRACOES.md) contém a referência completa, exemplos de ganchos e tratamento de erros.

## API PHP do portal

Um plugin confiável pode chamar `portal_push_send()` depois do carregamento do Portal Push. A função cria uma campanha na fila e retorna seu ID ou `WP_Error`.

Enviar para uma conta:

```php
$campaign_id = portal_push_send([
    'title' => 'Uma tarefa foi atribuída a você',
    'message' => 'Acesse o portal para consultar os detalhes.',
    'url' => home_url('/minhas-tarefas/'),
    'audience_type' => 'people',
    'audience_ids' => [123], // ID do usuário WordPress.
]);
```

Enviar por papéis do WordPress:

```php
$campaign_id = portal_push_send([
    'title' => 'Aviso editorial',
    'message' => 'Confira a atualização no portal.',
    'url' => home_url('/avisos/'),
    'audience_type' => 'roles',
    'audience_ids' => ['editor', 'author'], // Slugs existentes de papéis do WordPress.
    'ttl' => 86400,
    // Opcional: 'scheduled_at' => '2026-12-01 15:00:00', em UTC.
]);

if (is_wp_error($campaign_id)) {
    // Apresente ou registre o erro no fluxo do seu portal.
}
```

Para envio geral, use `audience_type => 'all'`. Público vazio, papel desconhecido e antigos IDs de grupos são rejeitados; não se transformam em envio geral. O título aceita 80 caracteres, a mensagem 300 e o payload total até 3.500 bytes. O link deve pertencer à mesma origem do portal.

## Atualizações e migração

O atualizador, presente desde 0.2.0, consulta o manifesto público no GitHub e disponibiliza a versão na tela **Plugins**. O ZIP é validado pelo SHA-256. Em **Portal Push → Configurações → Atualizações pelo WordPress**, habilite a instalação automática ou solicite uma consulta imediata. O processo respeita WP-Cron, permissões e políticas da hospedagem.

Se ainda estiver em 0.1.0, envie o ZIP atual manualmente uma vez para acrescentar o atualizador.

A migração para 0.3.0 preserva contas, papéis, chaves e inscrições. Campanhas pendentes destinadas aos antigos grupos próprios são canceladas: não existe equivalência automática com papéis do WordPress. Crie novas campanhas com o público correto. Os históricos e tabelas legados são preservados. O papel antigo `portal_push_member`, caso exista, permanece no WordPress para preservar o acesso; novas instalações não o criam. Campanhas individuais continuam válidas.

## Privacidade e limites

O plugin guarda vínculo com a conta, inscrições técnicas, descrição dos dispositivos, campanhas e resultados de envios/cliques. Os papéis são consultados no WordPress. Não coleta localização nem armazena IPs. A API de listagem não expõe endpoints ou segredos.

As ferramentas nativas de exportação/apagamento incluem esses dados. Apagar dados de push preserva a conta e seus papéis e desativa o recebimento. Excluir uma conta remove suas inscrições e entregas individuais. Por padrão, excluir o plugin preserva os dados; essa opção pode ser alterada nas configurações.

Gatilhos automáticos por publicação de posts, regras por campos personalizados, integrações específicas com ERP e caixas de mensagens internas não estão incluídos. Podem utilizar a API PHP ou ser desenvolvidos depois. A validação executada e os pontos de homologação estão em [VALIDACAO.md](VALIDACAO.md).
