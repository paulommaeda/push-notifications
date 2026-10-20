# Validação — Portal Push 0.2.0

Data: 20/10/2026.

## Ambiente e resultados

- WordPress 6.8.3, PHP 8.3.6 e banco local de testes com o plugin oficial SQLite Database Integration.
- 53 verificações de integração para contas, múltiplos perfis, inscrições por dispositivo, propriedade dos dispositivos, públicos, fila, agendamentos, cancelamento, repetição de tentativas e privacidade.
- 34 verificações HTTP com sessões e cookies reais, incluindo as seis telas administrativas, criação de pessoa/perfil, login por e-mail, associação a perfil, criação de campanha e proteção de endpoints e formulários.
- 8 verificações do service worker executadas em JavaScript: recebimento, fallback, destino do clique, bloqueio de destinos externos e rastreamento com token.
- 2 verificações criptográficas independentes: decifragem do corpo RFC 8291/AES-GCM e verificação da assinatura VAPID ES256 com Python/cryptography.
- Carregamento das bibliotecas validado em PHP sem autoloader anterior e em coexistência com a versão sem namespace de uma biblioteca Web Push.
- Validação sintática do PHP e do JavaScript.

Total dos conjuntos automatizados acima: **97 verificações aprovadas**. Os testes de transporte usam respostas HTTP simuladas; a assinatura e a criptografia são executadas de verdade. Não foram enviadas notificações a pessoas reais durante os testes.

## Atualizador GitHub (0.2.0)

Foram aprovadas **37 verificações adicionais** do atualizador. Elas cobrem validação do manifesto e da origem do pacote, falhas de rede/JSON, cache, sanitização do HTML, preservação da preferência de outros plugins, download com SHA-256, rejeição e remoção de ZIP adulterado, diretório de instalação e atualização nativa pelo `Plugin_Upgrader` em modo de execução automática.

O teste instalou uma versão futura simulada em uma cópia independente do plugin, mantendo o plugin ativo e preservando chaves, perfis e dispositivos. Nenhuma versão futura de teste foi publicada. Os testes de atualização usam rede simulada e arquivo ZIP real.

## Validação necessária no portal

1. Instalar em homologação, com a mesma versão PHP, banco MySQL/MariaDB, plugins, cache e configuração HTTPS da hospedagem.
2. Gerar as chaves de envio e criar dois perfis e duas contas de teste.
3. Autorizar o recebimento em pelo menos um computador e um celular.
4. Testar o envio para uma pessoa e conferir que a outra não recebe.
5. Testar um perfil e a combinação de dois perfis com uma pessoa em comum.
6. Testar o agendamento, a pausa de dispositivos e o logout/troca de conta.
7. Conferir o comportamento com a página fechada, com o dispositivo offline e com o modo de concentração do sistema.
8. No iPhone, validar pelo ícone adicionado à Tela de Início.
9. Conferir o funcionamento do agendador e dimensionar a hospedagem para o volume esperado.

## Limites desta versão

- Não foi validada a entrega em dispositivos físicos ou em um domínio HTTPS do usuário.
- A interface foi validada por renderização HTML e operações HTTP; não houve validação visual em um navegador gráfico nesta execução.
- Os testes locais usaram SQLite como adaptação para executar o WordPress; o banco de produção MySQL/MariaDB ainda precisa de homologação.
- Não houve teste de carga ou compatibilidade com todos os plugins/temas.
- Um PWA/service worker já existente precisa de integração específica; o plugin evita substituí-lo.
- A interface do sistema operacional/navegador pode variar; imagem, ícone e outros elementos não são exibidos de forma idêntica em todos os dispositivos.
- Aceitação pelo serviço de push não confirma leitura nem garante entrega imediata.
- Publicação automática de posts, regras por campos personalizados, notificações internas lidas/não lidas e canais de e-mail não estão incluídos nesta primeira versão.
