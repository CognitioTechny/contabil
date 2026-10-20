# Planejamento do módulo Contábil

Plano de implementação do pacote `cognitiotechny/contabil`: um módulo contábil **agnóstico**, headless, de partidas dobradas, instalável em qualquer aplicação CodeIgniter 4. Este documento consolida o planejamento anterior (épico e fases 1–5) com as restrições de `.agents/specs.md`. Quando os dois divergem, vale o `specs.md`; as divergências estão na seção 2.

> Status: **planejamento apenas. Nenhum código deve ser escrito antes de as decisões abertas (seção 9) serem fechadas.**

## 1. Objetivo e princípios

Entregar um motor contábil de partidas dobradas que qualquer host instale como pacote Composer e use por interfaces (ports) e eventos. O pacote nunca depende do host; o host depende do pacote por meio de um adaptador seu.

1. **Hexagonal (Ports & Adapters).**
   - `Domain`: PHP puro, sem dependência de framework (entidades, value objects, regras de partida dobrada, eventos de domínio).
   - `Application`: casos de uso e DTOs, dependendo só de interfaces.
   - `Infrastructure/CodeIgniter`: migrations, models/entities, repositórios, services, controllers de API, rotas, comandos `spark`.
2. **Headless.** Sem views, componentes de UI ou assets. Telas são do host e consomem a API.
3. **Sem referência ao host.** Nenhuma tabela, classe, FK ou `use` do host. Vínculo com documentos de origem é polimórfico e opaco: `origem_tipo` + `origem_id` + `chave_idempotencia`.
4. **Dinheiro é inteiro em unidade mínima (centavos)**, via value object `Dinheiro`. Nunca `float`. A conversão de/para `DECIMAL` fica no adaptador do host.
5. **Partidas dobradas reais:** N partidas por lançamento, efetivado só se ΣD = ΣC.
6. **Livro imutável:** lançamento efetivado não é editado nem apagado; correção por estorno. Período fechado não aceita lançamentos.
7. **Multi-entidade desde o v1** (`entidade_id` em tudo).
8. **Prefixo de tabelas configurável** (padrão `ctb_`), montado em tempo de migration; migrations versionadas dentro do pacote e sempre aditivas dentro da mesma major.
9. **Agnóstico de regras regionais:** o núcleo não impõe norma contábil nem plano de contas nacional. Planos padrão são seeds opcionais.
10. **Agnóstico de banco:** só `Forge`/`Migration` do CI4; testado em SQLite e MySQL, sem recursos exclusivos de um SGBD.
11. **SemVer**, CHANGELOG, testes e CI próprios.

## 2. Conciliação entre as issues antigas e o `specs.md`

| Tema | Issues antigas | `specs.md` | Decisão proposta |
|---|---|---|---|
| PHP | `^8.3` | 8.2+ | **`^8.2`** (hosts em 8.3 continuam compatíveis); CI testa 8.2, 8.3 e 8.4 |
| Interface | "views mínimas opcionais" e telas de referência | Sem UI; só Models, Entities, Services e API opcional | **Sem views.** Telas de referência saem do escopo do pacote |
| Normas | Seeds do Brasil, referência a ITG 2000 | Sem norma nacional | Seeds ficam em `resources/planos/`, **opcionais**, fora do núcleo; núcleo neutro |
| Estrutura | Hexagonal estrita | Models/Entities/Services do CI4 | Hexagonal mantida: Models/Entities/Services do CI4 vivem em `Infrastructure/CodeIgniter` como adaptadores |
| Relatórios | Razão, Diário, Balancete, DRE, Balanço | Balancete e "relatórios essenciais" nativos | Mantidos como serviços de leitura; PDF fica atrás de port opcional |
| Fora de escopo | SPED, folha, fiscal | Fiscal, folha e frontend | Mantido; ver seção 8 |

## 3. Arquitetura

```text
contabil/
├── composer.json            # cognitiotechny/contabil · php ^8.2 · psr/event-dispatcher
├── src/
│   ├── Domain/              # Conta, Lancamento, Partida, Exercicio, Periodo, Dinheiro, eventos
│   ├── Application/         # casos de uso, DTOs, Ports
│   └── Infrastructure/
│       └── CodeIgniter/     # Config, Database/Migrations, Models, Entities, Repositories,
│                            # Services, Controllers (API), Routes, Commands
├── config/                  # contabil.php
├── resources/planos/        # seeds opcionais de plano de contas
├── docs/                    # instalação, ports, eventos, OpenAPI, migração
└── tests/                   # Unit (Domain) · Contract (Ports) · Integration (DB) · Arquitetura
```

Autoload PSR-4 `CognitioTechny\Contabil\`. `codeigniter4/framework` fica em `require-dev`/`suggest`; Domain e Application não o exigem.

### Ports (cada um com implementação padrão e teste de contrato reutilizável)

`UsuarioAtualInterface`, `AutorizadorInterface`, `EntidadeAtualInterface`, `RelogioInterface`, `DispatcherEventosInterface` (PSR-14), `RenderizadorPdfInterface` (opcional), `ArmazenamentoInterface` (opcional).

### Requisitos de compatibilidade com hosts

O desenho precisa funcionar em hosts com estas características, sem citar nenhum deles no código:

- CodeIgniter 4.7+, PHP 8.3, MySQL, autenticação por provedor próprio e sistema de permissões por string.
- Valores monetários legados em `DECIMAL(15,2)`: o conversor decimal↔centavos é responsabilidade do host.
- Identificadores de usuário e de origem opacos (inteiro ou string): sem FK.
- Ativação por módulo: o host pode ligar e desligar rotas via config (`contabil.rotas.ativas`), e o `AutorizadorInterface` aceita permissões `contabil.*` mapeáveis.
- Nomenclatura de domínio em português (`Conta`, `Lancamento`, `Partida`), alinhada às tabelas `ctb_*`.
- Auditoria e privacidade por registro são do host. O pacote expõe eventos de domínio e o `usuario_id` em cada lançamento para isso.

## 4. Esquema de dados (prefixo configurável, padrão `ctb_`)

| Tabela | Finalidade | Colunas principais |
|---|---|---|
| `ctb_entidades` | Entidade contabilizada | id, nome, documento, moeda, config JSON |
| `ctb_exercicios` | Ano contábil | id, entidade_id, rotulo, data_inicio, data_fim, status (`ABERTO`/`ENCERRADO`) |
| `ctb_periodos` | Mês do exercício | id, exercicio_id, data_inicio, data_fim, status (`ABERTO`/`FECHADO`/`BLOQUEADO`) |
| `ctb_contas` | Plano hierárquico | id, entidade_id, codigo, nome, tipo, natureza, nivel, pai_id, aceita_lancamento, ativo, codigo_referencial (nullable); UNIQUE(entidade_id, codigo) |
| `ctb_lancamentos` | Cabeçalho | id, entidade_id, numero, data_competencia, data_registro, historico, status (`RASCUNHO`/`EFETIVADO`/`ESTORNADO`), estorno_de_id, origem_tipo, origem_id, chave_idempotencia, usuario_id, hash, hash_anterior |
| `ctb_partidas` | Linhas D/C | id, lancamento_id, conta_id, tipo (`D`/`C`), valor (BIGINT > 0), historico_complementar, ordem |
| `ctb_saldos` | Cache recalculável | conta_id, periodo_id, saldo_inicial, total_debitos, total_creditos, saldo_final |
| `ctb_regras_contabilizacao` | Mapa evento de origem → contas (uso opcional pelo host) | id, entidade_id, evento, condicao JSON, conta_debito_id, conta_credito_id, ativo |
| `ctb_versao` | Versão do schema | versao, aplicada_em |

Regras de schema:

- Todas as FKs são internas ao pacote.
- `valor` inteiro, `CHECK (valor > 0)` onde o SGBD suportar; o sinal vem de `tipo`.
- Sem soft delete em lançamentos efetivados; contas só são inativadas.
- Índices: `(entidade_id, data_competencia)`, `(conta_id, lancamento_id)` em partidas, `(origem_tipo, origem_id)`, UNIQUE `(entidade_id, chave_idempotencia)`.
- `ctb_saldos` é cache: a verdade são as partidas.
- O Livro Razão **não tem tabela**: é consulta sobre partidas.

## 5. Roadmap por fases

Cada fase vira uma issue neste repositório (seção 10). A ordem é estrita: 1 → 2 → 3 → 4 → 5.

### Fase 1: Núcleo do pacote
Esqueleto Composer, ports com implementação padrão, `config/contabil.php`, schema e migrations, comandos `contabil:install`, `contabil:migrate`, `contabil:status`, hierarquia de exceções de domínio, teste de arquitetura (`phpat` ou `deptrac`).

Aceite:
- `composer install` isolado funciona.
- Migrations sobem e descem em SQLite e MySQL.
- Nenhum `use` ou FK para código do host.
- Teste de arquitetura falha se Domain/Application importarem `CodeIgniter\*`.
- `docs/ports.md` descreve cada contrato com exemplo.

### Fase 2: Plano de contas e calendário
- **Conta:** código único por entidade com máscara configurável por nível; só analítica aceita lançamento; natureza deriva do tipo, com conta redutora; filho herda tipo do pai; mover não cria ciclo; conta com movimento não é excluída nem muda de tipo/natureza, só é inativada; analítica vira sintética só sem movimento.
- **Exercício e período:** criar exercício gera 12 períodos mensais; status `ABERTO` → `FECHADO` com reabertura controlada; sem sobreposição na mesma entidade.
- **Casos de uso:** `CriarConta`, `AtualizarConta`, `MoverConta`, `InativarConta`, `ReativarConta`, `ObterArvoreDeContas`, `ImportarPlanoDeContas` (CSV/JSON, tudo-ou-nada, erros por linha), `ExportarPlanoDeContas`, `CriarExercicio`, `ListarPeriodos`.
- **Seeds opcionais:** plano genérico e plano para serviços profissionais com valores de terceiros segregados do patrimônio da entidade, idempotentes (`contabil:seed-plano --modelo=`). Revisão por contador antes de chamar de "oficial".
- **API:** `/contas`, `/contas/{id}/mover`, `/contas/{id}/inativar`, `/contas/arvore`, `/contas/importar`, `/contas/exportar`, `/exercicios`, `/exercicios/{id}/periodos`. Autorização: `contabil.plano.ver`, `contabil.plano.editar`, `contabil.periodo.gerir`. Contrato em `docs/openapi.yaml`.

Aceite: invariantes cobertas por testes unitários sem banco; seed em entidade vazia gera árvore válida; criar exercício gera 12 períodos sem lacuna; API funciona com `AutorizadorInterface` fake em projeto vazio.

### Fase 3: Motor de lançamentos
- **Regras:** ≥ 2 partidas e ΣD = ΣC (em centavos); contas analíticas, ativas e da mesma entidade; competência em período `ABERTO`; histórico obrigatório; numeração sequencial sem lacunas por entidade/exercício atribuída na efetivação, segura sob concorrência; estados `RASCUNHO` → `EFETIVADO` → `ESTORNADO`; estorno cria lançamento invertido com `estorno_de_id` e motivo, uma única vez por original; correção = estorno + novo lançamento; idempotência por `chave_idempotencia`; lote atômico; cadeia de hash por entidade com `contabil:verificar-integridade`.
- **Períodos e exercício:** `FecharPeriodo` exige ausência de rascunhos; `ReabrirPeriodo` exige permissão e gera auditoria; `EncerrarExercicio` gera lançamento de apuração com conta de resultado parametrizável, reversível enquanto o exercício seguinte não estiver fechado.
- **Saldos:** cache em `ctb_saldos` atualizado na mesma transação; `contabil:recalcular-saldos`.
- **Eventos PSR-14:** `LancamentoEfetivado`, `LancamentoEstornado`, `PeriodoFechado`, `PeriodoReaberto`, `ExercicioEncerrado`.
- **API:** `/lancamentos` (CRUD de rascunho, `/efetivar`, `/estornar`, `/lote`), `/periodos/{id}/fechar|reabrir`, `/exercicios/{id}/encerrar`. Erros: desbalanceado 422, período fechado 409, idempotência repetida 200 com o lançamento existente. Todas as escritas transacionais.

Aceite:
- Casos inválidos são rejeitados sem efeito colateral.
- Efetivado é imutável por qualquer caminho.
- Estorno zera o efeito do original; segundo estorno é rejeitado.
- 50 efetivações concorrentes geram numeração contígua.
- Reenvio da mesma chave não duplica.
- `recalcular-saldos` reproduz `ctb_saldos`.
- `verificar-integridade` detecta `UPDATE` manual.
- Domain e Application testados sem banco e sem CodeIgniter.

Riscos: numeração sem lacunas sob concorrência (linha-contador com `SELECT … FOR UPDATE` versus tabela de sequência; decidir para MySQL e SQLite); encerramento e reabertura de exercício podem exigir sub-issue.

### Fase 4: Livros e demonstrações
Todos por entidade e período, considerando só lançamentos `EFETIVADO`; DTOs imutáveis, independentes de formato.

| Relatório | Regra-chave |
|---|---|
| Razão | Uma conta ou intervalo; saldo corrente; sintética agrega filhas; paginação por cursor |
| Diário | Ordem cronológica/numérica com partidas |
| Balancete | Saldo anterior, débitos, créditos, saldo atual; ΣD = ΣC; sinaliza divergência do cache |
| DRE | Estrutura derivada do plano, sem coeficientes fixos |
| Balanço | Ativo = Passivo + PL; redutoras com sinal correto |
| Extrato por origem | Lançamentos por `origem_tipo` + `origem_id` |

Formatos: JSON, CSV e PDF (PDF via `RenderizadorPdfInterface`, biblioteca em `suggest`). API em `/relatorios/*`, com `contabil.relatorios.ver` e `contabil.relatorios.exportar`. Convenção de sinais e arredondamento zero documentadas.

Aceite: conjunto de referência (≥ 30 lançamentos, com estorno e conta redutora) confere manualmente e fica versionado como teste; Balancete e Balanço sempre fecham; saldo final do Razão = saldo do Balancete; sintética = soma das filhas; Razão paginado de 10 mil lançamentos dentro da meta (a fixar, ex. < 2 s); CSV/PDF iguais ao JSON; relatórios são somente leitura e funcionam em réplica.

### Fase 5: Distribuição e prova de independência
O adaptador de qualquer host fica **fora** deste repositório. O que o pacote entrega:

- Teste de contrato dos ports para qualquer host validar seu adaptador.
- Guia de integração: como implementar os ports, usar `ctb_regras_contabilizacao`, `chave_idempotencia` (ex. `{sistema}:{tabela}:{id}:{versão}`), estorno + relançamento em vez de edição, fila de pendências quando faltar regra, conversão monetária centralizada.
- Guia de migração de dados legados (reconciliação por contagem e soma por conta, `--dry-run`, abortar em divergência).
- CI (GitHub Actions): PHP 8.2/8.3/8.4 × SQLite e MySQL; PHPUnit; PHPStan nível alto; PHP-CS-Fixer; teste de arquitetura.
- **Job de independência (gate de release):** cria um app CI4 vazio, instala o pacote por Composer, roda `contabil:install`, usa ports padrão, cria plano, lança, estorna, fecha período e emite Razão e Balancete.
- Documentação em `docs/`: instalação, configuração, ports, eventos, regras de contabilização, OpenAPI, migração, FAQ, política de versionamento e breaking changes.
- `README` (3 comandos), `CHANGELOG`, `CONTRIBUTING`, tag `v1.0.0`.

## 6. Instalação alvo (3 comandos)

```bash
composer require cognitiotechny/contabil
php spark contabil:install      # publica config e roda migrations
# registrar o adaptador do host (ports) na configuração
```

## 7. Estratégia de testes

| Camada | Tipo | Banco |
|---|---|---|
| Domain, Application | Unitários | Nenhum |
| Ports | Contrato (reutilizável pelo host) | Nenhum |
| Infrastructure | Integração | SQLite (CI também MySQL) |
| Estrutura | Arquitetura (`phpat`/`deptrac`) | Nenhum |
| Pacote inteiro | Independência (app vazio) | SQLite |

## 8. Fora de escopo (v1)

Gestão fiscal, folha e RH, UI, SPED/ECD/ECF, escrituração fiscal, conciliação bancária automática, depreciação e ativo imobilizado, multi-moeda, consolidação entre entidades. A arquitetura não deve impedir nenhum deles.

Observação: o módulo é ferramenta de escrituração, não parecer contábil. Planos de contas e modelos de demonstração precisam de validação por contador antes de uso oficial.

## 9. Decisões abertas

- [ ] **PHP mínimo:** confirmar `^8.2` (conforme `specs.md`) em vez de `^8.3`.
- [ ] **Idioma dos identificadores:** manter português no domínio e no schema (compatível com hosts em português) ou migrar para inglês. O `specs.md` está em inglês.
- [ ] **Licença:** MIT, conforme o repositório.
- [ ] **Seeds:** manter os planos padrão no pacote (opcionais) ou em repositório à parte.
- [ ] **Numeração sem lacunas:** linha-contador com bloqueio versus tabela de sequência.
- [ ] **Regime padrão** (competência ou caixa) para os exemplos e para a documentação de integração.
- [ ] **Ferramenta de teste de arquitetura:** `phpat` ou `deptrac`.

## 10. Backlog sugerido (issues a criar neste repositório)

1. `[Épico]` Módulo contábil agnóstico: arquitetura, escopo e roadmap (hub das fases abaixo)
2. `[Fase 1]` Núcleo do pacote: Composer, ports, configuração e schema
3. `[Fase 2]` Plano de contas e calendário contábil
4. `[Fase 3]` Motor de lançamentos: partidas dobradas, estorno, fechamento e saldos
5. `[Fase 4]` Livros e demonstrações
6. `[Fase 5]` Distribuição, CI e prova de independência

Cada issue repete a seção correspondente deste documento (escopo, tarefas, critérios de aceite, riscos).

## 11. Definição de pronto do épico

- [ ] Um app CodeIgniter 4 novo e vazio instala o pacote, migra, cria plano, lança, estorna, fecha período e emite relatórios, verificado em CI.
- [ ] Teste de arquitetura impede Domain/Application de importar `CodeIgniter\*`.
- [ ] Documentação de instalação, ports, eventos e API publicada no pacote.
- [ ] Release `v1.0.0` com CHANGELOG.
