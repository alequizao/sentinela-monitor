# 🛡️ Sentinela — monitor de disponibilidade

Monitor de uptime do **[monitoramento.top](https://monitoramento.top/)** (Traccar), com painel
web, histórico por dia, alertas no Direct do Instagram e notificações Web Push.

Roda **sem daemon e sem serviço externo**: um cron por minuto executa um mini-loop interno de
11 ciclos × 5s, cobrindo o minuto inteiro. Zero dependência de biblioteca — PHP puro (7.4),
cURL e arquivos JSON.

> Painel em produção: `https://publishdev.com.br/monitoramentotop/` (não indexado)

---

## ✨ O que ele faz

**Checagem em escada** (para no primeiro nível conclusivo, economizando requisição):

1. A página de login está no ar (HTTP 200 + shell do app React servido)
2. O backend Traccar responde (`GET /api/server`)
3. O **login real** funciona (`POST /api/session` retorna 200 + JSON com `id`) — só 1× a cada
   5 min, porque é o check mais caro e o que sofre rate limit

**Saúde do alerta** — checar a cada 5s sem virar spam:

| Freio | Efeito |
|---|---|
| Confirmação dupla | Falha isolada repete a checagem em 4s; só avisa se falhar de novo (blip fica só no log) |
| Carência de 5 min | Queda curta / reinício de serviço não vira Direct |
| Estabilidade de 2 min | "Voltou" só depois de OK contínuo — sem ping-pong |
| Cooldown de 10 min | Silêncio mínimo entre dois Direct quaisquer |
| Anti-flap | 4 transições numa hora → um único aviso "instável" + 2h de silêncio |
| Teto diário | Máximo de 8 Direct por dia; estourando, só log |
| Relatório das 23:50 | 1 Direct/dia com disponibilidade, tempo fora e incidentes |

**Painel (v1.3)** — filtro por data, linha do tempo de 24h, comparativo de 7/30/90 dias,
busca nos incidentes, exportação CSV/JSON e Web Push nativo (VAPID + aes128gcm).

---

## 📸 Telas do sistema

<!-- TODO: substituir pelos prints reais do painel (assets/img/*.png), em caminho relativo. -->
_Prints do painel ainda não versionados._

---

## 🗂️ Arquivos

| Arquivo | Papel |
|---|---|
| `monitor.php` | O monitor. Roda por cron, checa, decide o alerta, envia Direct + push, arquiva o dia |
| `index.php` | Painel público de status. Única coisa que o `.htaccess` deixa a web servir |
| `historico_backfill.php` | Utilitário CLI: reconstrói dias passados a partir do `monitor.log` |
| `sw.js` | Service worker do Web Push |
| `.htaccess` | Nega tudo por extensão; libera só o `index.php` |

**Gerados em runtime (fora do repo):** `estado.json` (estado atual + dia corrente),
`historico/AAAA-MM-DD.json` (um resumo congelado por dia), `push_subs.json` (inscrições push),
`monitor.log`.

---

## 📅 Histórico por dia

Na virada do dia, `arquivar_dia()` congela o dia que terminou em
`historico/AAAA-MM-DD.json` **antes** de zerar os contadores — gravação atômica (tmp + rename),
poda automática em 400 dias e incidente aberto na virada fechado às 23:59:59.

O painel lê **hoje** do `estado.json` (ao vivo, polling de 5s) e os **dias fechados** do
arquivo do dia (estáticos, sem polling).

Para trazer dias anteriores à adoção do arquivamento:

```bash
php historico_backfill.php          # não sobrescreve dado real
php historico_backfill.php --forcar # refaz tudo a partir do log
```

Dias reconstruídos do log levam `"estimado": true` (o total de checagens é estimativa) e
aparecem marcados no painel.

---

## 🚀 Instalação

```bash
# 1) credenciais do Traccar, FORA do docroot
cp monitor-traccar.conf.example /etc/monitor-traccar.conf
chmod 600 /etc/monitor-traccar.conf     # dono root

# 2) cron, 1× por minuto (o flock impede sobreposição)
* * * * * flock -n /caminho/monitor.lock php /caminho/monitor.php >> /caminho/cron.out 2>&1

# 3) pré-visualizar como os alertas saem no Direct, sem checar e sem enviar
php monitor.php --exemplos
```

Ajustes ficam nas constantes do topo do `monitor.php` (timeouts, carência, cooldown, teto
diário, horário do relatório, dias de histórico) e do `index.php` (`META_DISP`).

**Dependências externas:** o envio de Direct e o Web Push reaproveitam as libs e as chaves
VAPID do sistema de agendamentos (`AGENDA_DIR`). Sem elas, a checagem e o painel continuam
funcionando — só os avisos ficam mudos.

---

## 🔐 Segurança

- Credenciais do Traccar **nunca** ficam no docroot nem no repo — só em `/etc`, modo 600
- O `.htaccess` nega `.php`, `.log`, `.json`, `.lock`, `.out`, `.conf` e ocultos; **só** o
  `index.php` é servido. `historico/*.json` responde 403 na web
- O painel é público: mensagens de falha passam por `mascarar()`, que remove o usuário do
  Traccar antes de exibir
- `push_subs.json` (endpoints dos aparelhos inscritos) está no `.gitignore`

---

## 👨‍💻 Desenvolvedor

Sistema desenvolvido sob medida por **Alequizao**.

- **E-mail:** alequizao.dev@gmail.com
- **GitHub:** [@alequizao](https://github.com/alequizao)

Quer um sistema como este para o seu negócio? Entre em contato.

---

© Sentinela · Código proprietário, desenvolvido sob encomenda.
Uso, cópia ou redistribuição somente com autorização.
