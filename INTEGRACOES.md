# API de integração — Portal Push 0.4.0

Outros plugins na **mesma instalação do WordPress** podem disparar notificações por função PHP ou por um gancho. O Portal Push recebe o evento, monta a mensagem pré-configurada e cria uma campanha na fila. Não é necessário fazer uma requisição HTTP ou usar um token entre esses plugins.

## 1. Cadastrar a notificação pré-configurada

Em **Portal Push → Modelos e integrações**, cadastre:

| Campo | Exemplo |
|---|---|
| Nome | Tarefa concluída |
| Chave da integração | `tarefa_concluida` |
| Título | `Tarefa concluída` |
| Mensagem | `Olá {nome}, a tarefa {tarefa} foi concluída.` |
| Link | Um endereço do próprio portal, como `/tarefas/?id={tarefa_id}` |
| Destinatários padrão | O plugin que dispara informa o destinatário |
| Permitir disparos | Marcado |

A chave é permanente. O texto, o link, o público padrão e a ativação podem ser editados no painel sem alterar a chamada do outro plugin. Salvar um modelo não envia notificações.

O público padrão também pode ser um ou mais **papéis do WordPress** ou todos os habilitados. O envio geral precisa ser confirmado no cadastro. O código da integração pode selecionar outro público explicitamente.

## 2. Disparar pelo código do outro plugin

Execute a chamada em `init` ou em um evento posterior, quando os plugins já estiverem carregados:

```php
if (function_exists('portal_push_notify')) {
    $campaign_id = portal_push_notify('tarefa_concluida', [
        'user_id' => 123, // ID da conta WordPress que deve receber.
        'variables' => [
            'nome' => 'Ana',
            'tarefa' => 'Revisar o comunicado',
            'tarefa_id' => 456,
        ],
        'event_id' => 'tarefa:456:concluida',
    ]);

    if (is_wp_error($campaign_id)) {
        // Registre o código/erro no mecanismo de diagnóstico do seu plugin.
        $error_code = $campaign_id->get_error_code();
        $error_message = $campaign_id->get_error_message();
    }
}
```

O retorno é um **ID de campanha** ou `WP_Error`. O ID confirma a criação ou reutilização da campanha; não confirma entrega, exibição nem leitura. O envio acontece pela fila normal do Portal Push e pode ser acompanhado em **Campanhas**.

A conta precisa ter recebimento habilitado e dispositivos autorizados. A chamada não concede permissão ao navegador, não ativa dispositivos pausados e não altera papéis de usuários.

### Dados aceitos por `portal_push_notify($key, $context)`

| Campo em `$context` | Uso |
|---|---|
| `user_id` | Uma conta WordPress. Use este campo ou o par `audience_type`/`audience_ids`. |
| `audience_type` | `people`, `roles` ou `all`; use junto com `audience_ids`. |
| `audience_ids` | IDs de usuários para `people`; slugs de papéis para `roles`; array vazio para `all`. |
| `variables` | Array de valores simples para preencher `{nome}`, `{tarefa}` e outras variáveis do modelo. |
| `url` | Opcional: substitui o link do modelo por outro link da mesma origem do portal. |
| `event_id` | Opcional: texto de até 191 bytes que identifica o evento para evitar repetição. |

Sem público explícito, usa o público salvo no modelo. Um modelo com destinatário definido pelo plugin exige `user_id` ou um público explícito. Público vazio ou inválido é rejeitado; não se transforma em envio geral.

Uma variável deve começar por letra e conter letras, números ou sublinhado, como `{nome}` ou `{tarefa_id}`. Variáveis ausentes e valores compostos, como arrays/objetos, geram erro. A substituição não executa PHP nem shortcodes e não é recursiva. Os limites de título, mensagem e payload são conferidos depois de preencher os valores.

As variáveis do link são codificadas como componentes de URL. Passe seus valores sem codificação prévia. Variáveis não podem escolher o domínio do portal. Para um permalink completo, use `url` no contexto. Quando `url` é informado, variáveis usadas somente no link do modelo deixam de ser necessárias.

### Vários usuários

```php
$campaign_id = portal_push_notify('tarefa_concluida', [
    'audience_type' => 'people',
    'audience_ids' => [123, 456],
    'variables' => [
        'nome' => 'equipe',
        'tarefa' => 'Revisar o comunicado',
        'tarefa_id' => 789,
    ],
    'event_id' => 'tarefa:789:concluida',
]);
```

As variáveis são compartilhadas pelos destinatários da campanha. Para personalizar o nome de cada pessoa, faça uma chamada por usuário.

### Papéis do WordPress

```php
$campaign_id = portal_push_notify('tarefa_concluida', [
    'audience_type' => 'roles',
    'audience_ids' => ['editor', 'author'],
    'variables' => [
        'nome' => 'equipe editorial',
        'tarefa' => 'Revisar o comunicado',
        'tarefa_id' => 789,
    ],
    'event_id' => 'revisao:789:concluida',
]);
```

Os papéis são os registrados no WordPress. A combinação usa OU, sem duplicar a mensagem para um dispositivo. A elegibilidade é verificada novamente antes de enviar.

## 3. Conectar a um gancho do outro plugin

O nome abaixo é **ilustrativo**. Troque pelo nome e pelos argumentos do `do_action()` realmente existente no outro plugin:

```php
add_action('meu_plugin_tarefa_concluida', function ($tarefa_id, $responsavel_id, $titulo_tarefa) {
    if (!function_exists('portal_push_notify')) {
        return;
    }

    $responsavel = get_userdata((int)$responsavel_id);
    if (!$responsavel) {
        return;
    }

    $result = portal_push_notify('tarefa_concluida', [
        'user_id' => $responsavel->ID,
        'variables' => [
            'nome' => $responsavel->display_name,
            'tarefa' => $titulo_tarefa,
            'tarefa_id' => $tarefa_id,
        ],
        'event_id' => 'tarefa:' . $tarefa_id . ':concluida',
    ]);

    if (is_wp_error($result)) {
        // Encaminhe o erro ao mecanismo de diagnóstico do seu plugin.
    }
}, 10, 3);
```

O outro plugin precisa ter esse evento ou receber uma chamada no ponto da ação. Cadastrar a chave no Portal Push não intercepta automaticamente qualquer gancho do WordPress.

### Disparar pelo gancho oferecido pelo Portal Push

Se o outro plugin preferir emitir uma ação:

```php
do_action('portal_push_notify', 'tarefa_concluida', [
    'user_id' => 123,
    'variables' => [
        'nome' => 'Ana',
        'tarefa' => 'Revisar o comunicado',
        'tarefa_id' => 456,
    ],
    'event_id' => 'tarefa:456:concluida',
]);
```

`do_action()` não retorna o ID da campanha. Para obter o resultado, use a função. Falhas das duas formas também emitem:

```php
add_action('portal_push_integration_error', function ($error, $template_key) {
    // $error é WP_Error. Use código e mensagem no diagnóstico da integração.
}, 10, 2);
```

## Repetição do mesmo evento

Com `event_id`, a mesma combinação **modelo + público + evento** reutiliza a campanha existente. A ordem dos IDs/papéis não altera essa combinação. Outro usuário ou outro público pode receber uma campanha própria para o mesmo evento. O índice único no banco protege contra chamadas simultâneas.

Sem `event_id`, cada chamada cria uma campanha. Use uma nova identificação para uma nova ocorrência real; por exemplo, inclua a revisão ou o ID da ocorrência se uma tarefa puder ser concluída várias vezes. Repetir a chave de uma campanha cancelada/concluída retorna aquela campanha, sem criar novo disparo.

Editar, pausar ou excluir um modelo não altera campanhas que já foram criadas, pois o conteúdo fica salvo nelas. Para interromper pendências existentes, cancele a campanha na tela Campanhas. A chave de deduplicação existe enquanto a campanha correspondente permanecer no banco.

## Mensagens sem modelo

A API anterior continua disponível:

```php
$campaign_id = portal_push_send([
    'title' => 'Novo comunicado',
    'message' => 'Há uma atualização para você no portal.',
    'url' => home_url('/comunicados/'),
    'audience_type' => 'people',
    'audience_ids' => [123],
    'idempotency_key' => 'meu-plugin:comunicado:456:usuario:123', // Opcional, até 512 bytes.
]);
```

Na API direta, a chave é global e deve incluir um prefixo do plugin/evento. Sem ela, o comportamento das versões anteriores é mantido. Agendamento UTC e TTL da API direta continuam disponíveis conforme [LEIA-ME.md](LEIA-ME.md).

## Limite da integração

Estas funções são uma API PHP de servidor para código confiável dentro do mesmo WordPress. O plugin que recebe a ação valida a autorização e os dados do evento antes de escolher o destinatário. Não passe uma requisição pública diretamente para essas funções. Esta versão não cria uma rota REST de disparo nem altera automaticamente plugins de terceiros.
