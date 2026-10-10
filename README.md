# Reservation Coffee Break (`reservationcoffee`)

Microplugin para **GLPI 11.0.4+** que, logo após a reserva de uma sala de reunião, oferece ao usuário abrir o formulário nativo **"Solicitação de Coffee Break"**, exigindo **48 horas de antecedência** do início da reunião.

O calendário e o formulário de reservas continuam 100% nativos. O plugin não altera o core do GLPI, não cria tabelas e pode ser desativado a qualquer momento sem deixar resíduos funcionais.

## Como funciona

1. O usuário reserva uma das salas configuradas pelo fluxo nativo do GLPI.
2. O hook `item_add` de `Reservation` registra uma oferta pendente na sessão do usuário. A reserva nunca falha por causa do plugin.
3. Na página para a qual o GLPI redireciona depois da reserva, um script do plugin consulta `GET /plugins/reservationcoffee/pending` e, se houver oferta, abre a tela intermediária `/plugins/reservationcoffee/offer/{id}`.
4. A tela mostra a sala, o período e duas opções:
   - **Não, voltar ao calendário**;
   - **Solicitar Coffee Break**, que abre o Form nativo com o ID da reserva, o solicitante, a data, o horário de início e a sala já preenchidos.
5. Com menos de 48 horas de antecedência, a tela só informa a indisponibilidade.
6. A regra de 48 horas e a posse da reserva são **validadas de novo no servidor** quando o Form é enviado (hook `pre_item_add` de `Glpi\Form\AnswersSet`), sempre a partir do **ID da reserva** relido do banco. Abrir o Form direto pelo catálogo de serviços não contorna a regra: o envio é recusado e nenhum chamado é criado.
7. O chamado criado por um envio aceito recebe o título `Coffee Break - <Sala> - <dd/mm/aaaa HH:MM> - Reserva #<ID>` e, no início da descrição, a sala e o período **como estão na reserva**. Data, horário e sala digitados no Form podem divergir sem bloquear o envio; eles aparecem apenas na lista de respostas.

Reservas criadas pela API ou por linha de comando, edições e exclusões de reservas, e itens que não são salas configuradas seguem o comportamento nativo, sem oferta.

## Requisitos

- GLPI `>= 11.0.4` e `< 12.0.0` (validado em 11.0.4 e 11.0.11; veja [Compatibilidade entre versões do GLPI 11.0](#compatibilidade-entre-versões-do-glpi-110)).
- PHP `>= 8.2`, conforme o GLPI 11.
- Recomendado: suporte a fusos horários do GLPI habilitado (`php bin/console database:enable_timezones`) quando houver usuários em fusos diferentes do servidor.

## Instalação

1. Baixe `reservationcoffee-<versão>.zip` e confira o `.sha256` publicado junto.
2. Extraia o arquivo no diretório `plugins/` do GLPI. O resultado deve ser `plugins/reservationcoffee/setup.php`.
3. Instale e ative:

   ```bash
   php bin/console plugin:install --username=<usuario-admin> reservationcoffee
   php bin/console plugin:activate reservationcoffee
   php bin/console cache:clear
   ```

Enquanto não for configurado (próxima seção), o plugin fica inerte: sem configuração válida, nada é oferecido (fail closed).

## Configuração

### 1. Formulário nativo de Coffee Break

Crie pelo administrador de formulários do GLPI (Administração > Formulários) o Form **"Solicitação de Coffee Break"** com:

| Pergunta | Tipo | Obrigatória | Preenchida pelo plugin | Condição |
| --- | --- | --- | --- | --- |
| ID da reserva | Número | Sim | Sim | — |
| Solicitante | Texto curto | Não | Nome do usuário logado | — |
| Data | Texto curto | Não | Data de início, no formato de data do usuário | — |
| Horário de início | Texto curto | Não | `HH:MM` | — |
| Sala | Texto curto | Não | Nome do item reservado | — |
| Participantes | Número | Sim | Não | Validação "corresponde à expressão regular" `/^[1-9][0-9]{0,3}$/` |
| Necessita de Coffee Break | Botões de opção: Sim (`sim`, padrão) / Não (`nao`) | Sim | Não | — |
| Itens solicitados | Caixas de seleção: Café, Água, Refrigerante, Suco, Salgados, Doces | Sim, quando visível | Não | Visível se "Necessita de Coffee Break" for igual a `sim` |
| Observações | Texto longo | Não | Não | — |

As perguntas preenchidas pelo plugin continuam editáveis. "Solicitante", "Data", "Horário de início" e "Sala" são de texto curto porque o GLPI só aceita pré-preenchimento por URL em texto, número, e-mail, texto longo, urgência e tipo de requisição. O tipo nativo "Solicitante" não tem a opção "usuário atual" como padrão. O solicitante do chamado continua sendo, de forma nativa, quem preencheu o Form.

Destino **Chamado** (Ticket):

- **Categoria:** Administrativo > Coffee Break;
- **Atribuído a:** grupo Facilities / Copa;
- **Título:** `Coffee Break - <Sala> - <Data> <Horário de início> - Reserva #<ID da reserva>`, com as respostas. O plugin substitui esse título pelos dados reais da reserva (veja [Como funciona](#como-funciona)), e o modelo nativo vale se o plugin estiver desativado;
- **Descrição:** a configuração automática, que lista todas as perguntas respondidas, inclusive participantes e itens.

Ajuste a política de acesso do Form ao público que pode reservar salas.

### 2. IDs no plugin

A configuração fica na tabela nativa de configuração do GLPI, no contexto `plugin:reservationcoffee`:

| Chave | Valor |
| --- | --- |
| `reservable_item_ids` | IDs dos **itens reserváveis** (`ReservationItem`, não dos ativos) das salas, separados por vírgula |
| `coffee_form_id` | ID do Form de Coffee Break |
| `reservation_question_id` | ID da pergunta "ID da reserva" desse Form |
| `requester_question_id`, `date_question_id`, `time_question_id`, `room_question_id` | Opcionais: IDs das perguntas "Solicitante", "Data", "Horário de início" e "Sala", a pré-preencher |
| `participants_question_id` | Opcional: ID da pergunta "Participantes". O envio é recusado se a resposta não for um inteiro maior que zero |
| `pending_ttl_seconds` | Validade da oferta pendente na sessão, em segundos (padrão 300, limitado entre 5 e 3600) |

```bash
php bin/console config:set --context=plugin:reservationcoffee reservable_item_ids 1,2,3,4
php bin/console config:set --context=plugin:reservationcoffee coffee_form_id 3
php bin/console config:set --context=plugin:reservationcoffee reservation_question_id 17
php bin/console cache:clear
```

Onde encontrar os IDs:

- **Item reservável:** no calendário da sala, a URL mostra `front/reservation.php?reservationitems_id=<ID>`.
- **Form:** a URL de preenchimento é `/Form/Render/<ID>`.
- **Pergunta:** consulta somente leitura:

  ```sql
  SELECT q.id
  FROM glpi_forms_questions q
  JOIN glpi_forms_sections s ON s.id = q.forms_sections_id
  WHERE s.forms_forms_id = <ID do Form> AND q.name = 'ID da reserva';
  ```

Qualquer valor malformado (por exemplo `1,abc`) desliga o recurso correspondente por inteiro. Cada reserva criada nesse estado registra `CONFIGURATION_ERROR invalid_keys=...` no log do plugin.

## Permissões

- **Dono da reserva:** pode ver a oferta e solicitar o Coffee Break da própria reserva.
- **Outros usuários:** só têm acesso se puderem ver a reserva **e** tiverem o direito `reservation` com atualização (gestores de reservas).
- **Demais casos:** recebem `403`. IDs inexistentes ou malformados recebem `404`.

## Logs

O plugin escreve em `files/_log/reservationcoffee.log`, no diretório de logs do GLPI. Cada linha traz somente:

- operação;
- categoria do erro (`VALIDATION_ERROR`, `AUTHORIZATION_ERROR`, `NOT_FOUND`, `CONFIGURATION_ERROR` ou `UNKNOWN_ERROR`);
- IDs e, em caso de exceção, a classe e o arquivo:linha.

Nunca registra nomes, respostas, comentários ou dados de sessão.

## Runbook de produção

1. **Janela e backup:** faça backup do banco e do diretório `files/` do GLPI.
2. **Pacote:** confira o checksum do zip (`shasum -a 256 -c reservationcoffee-<versão>.zip.sha256`) e extraia em `plugins/`.
3. **Instalação:** rode os comandos da seção [Instalação](#instalação).
4. **Configuração:** crie ou valide o Form e grave os IDs (seção [Configuração](#configuração)). Depois, rode `php bin/console cache:clear`.
5. **Smoke test** com um usuário **Self-Service** (baixo privilégio):
   - reserve uma sala configurada com mais de 48 horas de antecedência. A tela "Coffee Break" deve aparecer, e "Solicitar Coffee Break" deve abrir o Form com o ID da reserva, o solicitante, a data, o horário e a sala preenchidos. Não envie o Form, a menos que queira gerar um chamado de teste;
   - reserve uma sala com menos de 48 horas de antecedência. A tela deve mostrar o aviso de indisponibilidade, sem link para o Form;
   - reserve um item que não seja sala configurada. O fluxo deve ser o nativo, sem tela do plugin;
   - exclua as reservas de teste.
6. **Monitoramento:** acompanhe `files/_log/reservationcoffee.log` e `php-errors.log` nas primeiras horas.

### Rollback

- **Imediato:** `php bin/console plugin:deactivate reservationcoffee`. O GLPI volta ao comportamento 100% nativo, e o JS e as rotas do plugin deixam de ser carregados.
- **Remoção completa:** `php bin/console plugin:uninstall reservationcoffee`, que apaga a configuração `plugin:reservationcoffee`. Em seguida, remova o diretório `plugins/reservationcoffee`.
- **Dados preservados:** reservas e Forms nunca são alterados pelo plugin. Dos chamados, ele só define o título e o resumo da reserva na criação de um pedido de Coffee Break aceito.

## Desenvolvimento e testes

Requisitos: Docker, Node.js 22, PHP 8.2+ com Composer e, para os locales, GNU gettext.

```bash
tools/init-env.sh            # cria .env com senhas aleatórias (nunca versionado)
tools/e2e-up.sh              # GLPI 11.0.11 + MariaDB, instala/ativa o plugin e aplica o seed sintético

cd reservationcoffee && composer install
composer lint && composer phpstan && composer cs && composer test   # PHPStan precisa de GLPI_SOURCE_DIR
cd ..

tools/integration-test.sh    # PHPUnit dentro do container do GLPI, contra a base E2E
cd e2e && npm ci && npx playwright install chromium && npx playwright test
```

- **PHPStan:** `GLPI_SOURCE_DIR` aponta para uma cópia extraída do GLPI 11.0.x, por exemplo o `.tgz` oficial da release. A CI verifica a 11.0.11 e a versão mínima 11.0.4.
- **E2E:** a suíte cobre o fluxo principal, segurança (IDOR, CSRF, bypass pelo catálogo, entidades), robustez (TTL, API, configuração inválida, fuso do usuário, duas abas) e regressão do calendário nativo.
- **Locales:** `tools/update-locales.sh` extrai as strings, atualiza os `.po` (pt_BR, en_GB e es_ES) e compila os `.mo`.
- **Pacote:** `tools/package.sh` gera `dist/reservationcoffee-<versão>.zip`.
- **Homologação do pacote:** `tools/homologate-package.sh` roda a suíte E2E contra esse pacote, e não contra o código-fonte.
- **Outra versão do GLPI:** a stack aceita `E2E_GLPI_VERSION`. Para rodá-la ao lado da padrão, use outro projeto e outra porta, por exemplo `COMPOSE_PROJECT_NAME=rc-glpi-1104 E2E_GLPI_VERSION=11.0.4 E2E_HTTP_PORT=8092 tools/e2e-up.sh`, com as mesmas variáveis nos comandos de teste. A CI roda integração e E2E em 11.0.4 e 11.0.11.
- **Servidor de homologação remoto:** `tools/remote-e2e.sh` roda a mesma suíte contra um GLPI acessado por SSH (nunca produção), com o plugin já instalado e ativo. `prepare` copia os helpers para `/tmp/rc-e2e` no servidor, `seed` cria usuários, entidade e Form usando as salas existentes (`E2E_SEED_ROOM_ITEMS`) e, como item que não é sala, um periférico "[E2E-RC] Projetor" ou um item existente em `E2E_SEED_NON_ROOM_ITEM`. Não use itens cuja reserva nativa seja proibida no servidor, como veículos reservados por outro plugin. `test` roda o Playwright e `teardown` remove os helpers. Os endereços e caminhos do servidor vêm de variáveis de ambiente descritas no cabeçalho do script; as senhas dos usuários E2E vêm do `.env` local. O seed não é desfeito automaticamente: remova os dados de teste ao final. Antes do primeiro teste, a suíte confere que os IDs de usuário de `e2e/.auth/seed.json` são os dos usuários E2E do servidor alvo e para se não forem. Rode o `seed` sempre que trocar de servidor.

## Compatibilidade entre versões do GLPI 11.0

- **Retorno após a reserva:** a 11.0.11 volta ao calendário com `defaultDate`; a 11.0.4 usa `month` e `year` (ou `tab_params[...]`). O script reconhece os dois formatos e só consulta `/pending` quando a URL tem `defaultDate` ou o par completo `month` + `year`. Esses parâmetros só aparecem no retorno do formulário de reserva e no link "Ver planejamento" da lista de reservas; a navegação dentro do calendário não recarrega a página.
- **Custo:** na 11.0.4, abrir uma agenda pelo link "Ver planejamento" também gera uma consulta a `/pending`. É um GET leve, que só lê a sessão e responde `204` quando não há oferta; nada é gravado e não há redirecionamento.
- **Rotas de ação:** na 11.0.4 o router de plugins casa as rotas como se toda requisição fosse GET. Por isso "Solicitar" e "Não" não declaram restrição de método na rota: o controller exige POST (GET recebe `405`) e o core continua validando o token CSRF no POST.
- **Fuso pessoal do usuário (limitação do core 11.0.4):** a 11.0.4 só aplica o fuso definido nas preferências do usuário na sessão do banco durante o login. Nos requests seguintes, as datas digitadas são gravadas no fuso do servidor. O plugin avalia a reserva exatamente como o GLPI a gravou. Na 11.0.4, mantenha os usuários no fuso do servidor ou atualize para a 11.0.11. O teste E2E-20 é pulado nessa versão.
- **Notificação de reserva com "grupo técnico do item" (core 11.0.4):** se uma notificação de reserva tiver como destinatário o grupo técnico responsável pelo item e o item reservável não tiver grupo técnico (ativo personalizado ou nativo, como um periférico), a 11.0.4 falha ao criar a reserva ("Empty IN are not allowed"), com ou sem este plugin. Defina um grupo técnico nesses itens (os membros passam a receber essa notificação), ajuste os destinatários da notificação ou atualize o GLPI. Reservas criadas com `_disablenotif` não disparam a notificação.
- **Pré-preenchimento do Form:** a 11.0.4 só pré-preenche perguntas cujo UUID não tenha `.`, como os gerados pelo editor de Forms atual.
- **Participantes igual a "0" (core 11.0.4 e 11.0.11):** o GLPI não aplica as condições de validação a respostas que considera vazias, como "0". Por isso o plugin também recusa o envio quando `participants_question_id` está configurado.

## Limitações conhecidas e próximos passos (v2)

Os riscos residuais abaixo foram avaliados e aceitos para a v0.1.0 em 2026-10-09.

- **Duas reservas quase simultâneas:** se forem concluídas em abas diferentes dentro do mesmo segundo, a primeira aba a consultar recebe a oferta mais recente. O uso sequencial em várias abas funciona.
- **Campos editáveis:** Solicitante, Data, Horário de início e Sala podem ser alterados no Form. O título e o resumo do chamado sempre vêm da reserva, mas as respostas digitadas também aparecem na descrição.
- **Chamado sem Coffee Break:** com "Necessita de Coffee Break" = Não, o chamado também é criado, sem itens.
- **Mesma reserva, vários pedidos:** o Form pode ser enviado mais de uma vez para a mesma reserva (vários chamados).
- **Chamados abertos fora do Form:** chamados abertos manualmente na categoria Coffee Break não passam pela regra de 48 horas. Restrinja a categoria por perfil, se necessário.
- **Escopo da trava:** a validação no envio vale apenas para o Form configurado em `coffee_form_id`. Cópias do Form não são protegidas.
- **Abrir a oferta conta como entrega:** qualquer GET em `/offer/{id}` marca a oferta pendente como já mostrada. Se o usuário abrir essa URL antes do redirecionamento (por exemplo, em outra aba), o calendário não redireciona de novo para ela.
- **Previstos para a v2:** configuração por entidade e um token de contexto de uso único, associado ao chamado, para correlacionar oferta, reserva e pedido.

## Licença

GPLv3 ou posterior. Veja [`reservationcoffee/LICENSE`](reservationcoffee/LICENSE).
