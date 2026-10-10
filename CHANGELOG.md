# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/); versões seguem [SemVer](https://semver.org/lang/pt-BR/).

## [0.1.0] - 2026-10-09

### Adicionado

- Oferta de Coffee Break após a reserva de uma sala configurada, por meio de uma tela intermediária com as opções "Não, voltar ao calendário" e "Solicitar Coffee Break".
- Regra de 48 horas de antecedência:
  - calculada por timestamp no fuso efetivo do GLPI, inclusive no fuso do usuário;
  - recalculada no aceite da oferta;
  - revalidada no servidor no envio do Form nativo (`pre_item_add` de `AnswersSet`), o que bloqueia o atalho pelo catálogo.
- Abertura do Form nativo com o "ID da reserva" pré-preenchido.
- Configuração pela tabela nativa (`config:set --context=plugin:reservationcoffee`), com validação estrita e fail closed.
- Log próprio (`reservationcoffee.log`) contendo apenas IDs e categorias de erro.
- Traduções pt_BR (principal), en_GB e es_ES.
- Testes:
  - unitários (PHPUnit);
  - de integração dentro do GLPI 11.0.4 e 11.0.11;
  - E2E com Playwright (E2E-01 a E2E-30) nas duas versões.
- Compatibilidade com GLPI 11.0.4 a 11.0.x (`min` 11.0.4, `max` 12.0.0 exclusivo), validada em 11.0.4 e 11.0.11:
  - reconhece os dois formatos de retorno pós-reserva (`defaultDate` e `month`/`year`);
  - exige POST no controller das ações "Solicitar" e "Não", porque o router de plugins da 11.0.4 ignora a restrição de método da rota.
- CI no GitHub Actions: lint, PHPStan, coding style, testes, auditoria de dependências, verificação de locales, secret scan, integração e E2E.

### Limitações conhecidas

Riscos residuais aceitos para esta versão (detalhes em [Limitações conhecidas](README.md#limitações-conhecidas-e-próximos-passos-v2)):
- a mesma reserva pode gerar mais de um pedido de Coffee Break;
- chamados abertos manualmente na categoria não passam pela regra de 48 horas;
- cópias do Form configurado não são protegidas;
- duas reservas concluídas em abas diferentes no mesmo segundo podem trocar a oferta;
- abrir `/offer/{id}` marca a oferta como já mostrada.

Na 11.0.4, o fuso pessoal do usuário só vale no login (limitação do core); veja a seção de compatibilidade do README.
