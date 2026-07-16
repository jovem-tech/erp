"""Gera o diagrama SVG do fluxo de status da OS espelhando o sistema real.

Fontes da verdade (levantadas em 2026-07-16 no banco sistema_hml e no código):

  - Tabela `os_status` (27 status ativos, agrupados por `grupo_macro`).
  - Tabela `os_status_transicoes` (69 transições ativas) — embutida abaixo em
    REAL_TRANSITIONS para o script se auto-verificar.
  - Regra de negócio (OrderWorkflowService::updateStatus): mover PARA um dos 5
    status de encerramento (grupo_macro='encerrado') é bloqueado no fluxo
    normal ("closure_status_requires_baixa_flow"); só a BAIXA DA OS
    (OrderClosureService::close) aplica esses status — e ela pode partir de
    QUALQUER etapa aberta, ignorando o catálogo de transições. Por isso as
    transições cadastradas com destino 'encerrado' são inertes e NÃO são
    desenhadas como setas; o encerramento é representado pela "porta de baixa"
    no topo da raia ENCERRADO.
  - `cancelado` NÃO é encerramento (grupo_macro='cancelado'): é alcançável
    pelo fluxo normal e possui reabertura (cancelado -> triagem).

O script valida, em tempo de geração, que o conjunto de setas desenhadas é
exatamente o conjunto de transições utilizáveis do banco — se o catálogo
mudar (tela Conhecimento > Fluxo da OS), atualize REAL_TRANSITIONS e o
desenho, ou a geração falha apontando a diferença.
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path
from xml.sax.saxutils import escape


ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / "diagrama_fluxo_os_organizado.svg"
OUTPUT_PNG = ROOT / "diagrama_fluxo_os_organizado.png"

WIDTH = 1780
HEIGHT = 1560


# ---------------------------------------------------------------------------
# Dados reais (banco: os_status / os_status_transicoes, 2026-07-16)
# ---------------------------------------------------------------------------

# grupo_macro='encerrado': só alcançáveis pela baixa da OS (porta única).
CLOSURE_CODES = {
    "entregue_reparado_pago",
    "entregue_reparado_sem_custo",
    "entregue_reparado_garantia",
    "devolvido_sem_reparo",
    "descartado",
}

# As 69 linhas ativas de os_status_transicoes (origem -> destino).
REAL_TRANSITIONS = [
    ("triagem", "diagnostico"),
    ("triagem", "irreparavel"),
    ("triagem", "irreparavel_disponivel_loja"),
    ("triagem", "reparo_recusado"),
    ("triagem", "devolvido_sem_reparo"),
    ("triagem", "descartado"),
    ("triagem", "cancelado"),
    ("diagnostico", "triagem"),
    ("diagnostico", "aguardando_avaliacao"),
    ("diagnostico", "verificacao_garantia"),
    ("diagnostico", "aguardando_orcamento"),
    ("diagnostico", "aguardando_reparo"),
    ("diagnostico", "irreparavel"),
    ("diagnostico", "devolvido_sem_reparo"),
    ("diagnostico", "descartado"),
    ("diagnostico", "cancelado"),
    ("aguardando_avaliacao", "diagnostico"),
    ("aguardando_avaliacao", "verificacao_garantia"),
    ("aguardando_avaliacao", "aguardando_orcamento"),
    ("aguardando_avaliacao", "aguardando_autorizacao"),
    ("aguardando_avaliacao", "aguardando_reparo"),
    ("aguardando_avaliacao", "reparo_execucao"),
    ("aguardando_avaliacao", "retrabalho"),
    ("aguardando_avaliacao", "aguardando_peca"),
    ("aguardando_avaliacao", "pagamento_pendente"),
    ("verificacao_garantia", "diagnostico"),
    ("verificacao_garantia", "aguardando_orcamento"),
    ("verificacao_garantia", "cumprimento_garantia"),
    ("verificacao_garantia", "garantia_concluida"),
    ("verificacao_garantia", "devolvido_sem_reparo"),
    ("verificacao_garantia", "descartado"),
    ("verificacao_garantia", "entregue_reparado_garantia"),
    ("aguardando_orcamento", "aguardando_autorizacao"),
    ("aguardando_orcamento", "cancelado"),
    ("aguardando_autorizacao", "aguardando_reparo"),
    ("aguardando_autorizacao", "reparo_recusado"),
    ("aguardando_autorizacao", "cancelado"),
    ("aguardando_reparo", "reparo_execucao"),
    ("aguardando_reparo", "aguardando_peca"),
    ("reparo_execucao", "retrabalho"),
    ("reparo_execucao", "testes_operacionais"),
    ("reparo_execucao", "aguardando_peca"),
    ("reparo_execucao", "pagamento_pendente"),
    ("reparo_execucao", "reparo_concluido"),
    ("reparo_execucao", "irreparavel"),
    ("reparo_execucao", "cancelado"),
    ("retrabalho", "testes_operacionais"),
    ("testes_operacionais", "aguardando_peca"),
    ("testes_operacionais", "testes_finais"),
    ("aguardando_peca", "reparo_execucao"),
    ("aguardando_peca", "cancelado"),
    ("pagamento_pendente", "entregue_pagamento_pendente"),
    ("pagamento_pendente", "reparado_disponivel_loja"),
    ("pagamento_pendente", "entregue_reparado_pago"),
    ("entregue_pagamento_pendente", "aguardando_avaliacao"),
    ("entregue_pagamento_pendente", "entregue_reparado_pago"),
    ("entregue_pagamento_pendente", "devolvido_sem_reparo"),
    ("entregue_pagamento_pendente", "descartado"),
    ("testes_finais", "reparo_concluido"),
    ("reparo_concluido", "entregue_pagamento_pendente"),
    ("reparo_concluido", "reparado_disponivel_loja"),
    ("reparo_concluido", "entregue_reparado_pago"),
    ("reparado_disponivel_loja", "entregue_reparado_pago"),
    ("irreparavel", "irreparavel_disponivel_loja"),
    ("irreparavel", "devolvido_sem_reparo"),
    ("irreparavel", "descartado"),
    ("irreparavel_disponivel_loja", "devolvido_sem_reparo"),
    ("reparo_recusado", "devolvido_sem_reparo"),
    ("cancelado", "triagem"),
]

# Transições utilizáveis pelo fluxo normal ("Alterar status"): as que apontam
# para um encerramento são inertes (bloqueadas por
# closure_status_requires_baixa_flow) e ficam de fora do desenho de setas.
USABLE_TRANSITIONS = {
    (origem, destino)
    for origem, destino in REAL_TRANSITIONS
    if destino not in CLOSURE_CODES
}


def card_points(x: int, y: int, w: int, h: int) -> dict[str, tuple[int, int]]:
    return {
        "left": (x, y + h // 2),
        "right": (x + w, y + h // 2),
        "top": (x + w // 2, y),
        "bottom": (x + w // 2, y + h),
        "center": (x + w // 2, y + h // 2),
    }


COLORS = {
    "page": "#FFFFFF",
    "ink": "#1F2937",
    "muted": "#495057",
    "line_gray": "#AAB4C0",
    "line_blue": "#1864AB",
    "line_purple": "#7048E8",
    "lane_blue_fill": "#EDF5FF",
    "lane_blue_stroke": "#A5D8FF",
    "lane_green_fill": "#F1F8F2",
    "lane_green_stroke": "#B7E4C7",
    "lane_yellow_fill": "#FFF8E1",
    "lane_yellow_stroke": "#E9C46A",
    "lane_orange_fill": "#FFF1E6",
    "lane_orange_stroke": "#F4A261",
    "lane_red_fill": "#FFF1F2",
    "lane_red_stroke": "#F08080",
    "lane_purple_fill": "#F5F0FF",
    "lane_purple_stroke": "#CDB4DB",
    "primary_fill": "#4DABF7",
    "primary_stroke": "#1864AB",
    "primary_text": "#FFFFFF",
    "success_fill": "#40C057",
    "success_stroke": "#2B8A3E",
    "success_text": "#FFFFFF",
    "secondary_fill": "#F8F9FA",
    "secondary_stroke": "#ADB5BD",
    "secondary_text": "#495057",
    "port_fill": "#F3F0FF",
}


LANES = [
    {"title": "G1 · RECEPÇÃO", "x": 50, "y": 80, "w": 180, "h": 140, "fill": COLORS["lane_blue_fill"], "stroke": COLORS["lane_blue_stroke"]},
    {"title": "G1 · DIAGNÓSTICO", "x": 300, "y": 80, "w": 220, "h": 330, "fill": COLORS["lane_blue_fill"], "stroke": COLORS["lane_blue_stroke"]},
    {"title": "G1 · ORÇAMENTO", "x": 600, "y": 80, "w": 220, "h": 240, "fill": COLORS["lane_blue_fill"], "stroke": COLORS["lane_blue_stroke"]},
    {"title": "G2 · EXECUÇÃO", "x": 600, "y": 470, "w": 220, "h": 430, "fill": COLORS["lane_green_fill"], "stroke": COLORS["lane_green_stroke"]},
    {"title": "G2 · QUALIDADE", "x": 890, "y": 470, "w": 220, "h": 260, "fill": COLORS["lane_yellow_fill"], "stroke": COLORS["lane_yellow_stroke"]},
    {"title": "G2 · INTERRUPÇÃO", "x": 1180, "y": 470, "w": 260, "h": 360, "fill": COLORS["lane_orange_fill"], "stroke": COLORS["lane_orange_stroke"]},
    {"title": "G3 · FINALIZADO SEM REPARO", "x": 600, "y": 960, "w": 220, "h": 340, "fill": COLORS["lane_red_fill"], "stroke": COLORS["lane_red_stroke"]},
    {"title": "G3 · CONCLUÍDO", "x": 890, "y": 960, "w": 220, "h": 340, "fill": COLORS["lane_green_fill"], "stroke": COLORS["lane_green_stroke"]},
    {"title": "G3 · ENCERRADO (via baixa)", "x": 1180, "y": 960, "w": 260, "h": 560, "fill": COLORS["lane_purple_fill"], "stroke": COLORS["lane_purple_stroke"]},
    {"title": "G3 · CANCELADO", "x": 1500, "y": 960, "w": 180, "h": 180, "fill": COLORS["lane_red_fill"], "stroke": COLORS["lane_red_stroke"]},
]


# Um card por status ativo de os_status (código -> card).
CARDS = {
    "triagem": {"x": 80, "y": 120, "w": 120, "h": 62, "lines": ["Triagem"], "kind": "primary"},
    "diagnostico": {"x": 340, "y": 120, "w": 140, "h": 62, "lines": ["Diagnóstico", "técnico"], "kind": "primary"},
    "aguardando_avaliacao": {"x": 340, "y": 225, "w": 140, "h": 62, "lines": ["Aguardando", "avaliação"], "kind": "secondary"},
    "verificacao_garantia": {"x": 340, "y": 320, "w": 140, "h": 62, "lines": ["Verificação", "de garantia"], "kind": "secondary"},
    "aguardando_orcamento": {"x": 640, "y": 120, "w": 140, "h": 62, "lines": ["Aguardando", "orçamento"], "kind": "primary"},
    "aguardando_autorizacao": {"x": 640, "y": 225, "w": 140, "h": 62, "lines": ["Aguardando", "autorização"], "kind": "primary"},
    "aguardando_reparo": {"x": 640, "y": 510, "w": 140, "h": 62, "lines": ["Aguardando", "reparo"], "kind": "primary"},
    "reparo_execucao": {"x": 640, "y": 615, "w": 140, "h": 62, "lines": ["Em execução", "do serviço"], "kind": "primary"},
    "cumprimento_garantia": {"x": 640, "y": 720, "w": 140, "h": 62, "lines": ["Cumprimento", "de garantia"], "kind": "secondary"},
    "retrabalho": {"x": 640, "y": 825, "w": 140, "h": 62, "lines": ["Retrabalho"], "kind": "secondary"},
    "testes_operacionais": {"x": 930, "y": 530, "w": 140, "h": 62, "lines": ["Testes", "operacionais"], "kind": "primary"},
    "testes_finais": {"x": 930, "y": 635, "w": 140, "h": 62, "lines": ["Testes finais"], "kind": "primary"},
    "aguardando_peca": {"x": 1230, "y": 510, "w": 160, "h": 62, "lines": ["Aguardando", "peça"], "kind": "secondary"},
    "pagamento_pendente": {"x": 1230, "y": 615, "w": 160, "h": 62, "lines": ["Pagamento", "pendente"], "kind": "secondary"},
    "entregue_pagamento_pendente": {"x": 1220, "y": 720, "w": 180, "h": 74, "lines": ["Entregue —", "pendência financeira"], "kind": "secondary"},
    "irreparavel": {"x": 640, "y": 1000, "w": 140, "h": 62, "lines": ["Irreparável"], "kind": "secondary"},
    "irreparavel_disponivel_loja": {"x": 620, "y": 1105, "w": 180, "h": 74, "lines": ["Irreparável,", "disponível para", "retirada"], "kind": "secondary"},
    "reparo_recusado": {"x": 640, "y": 1220, "w": 140, "h": 62, "lines": ["Reparo", "recusado"], "kind": "secondary"},
    "reparo_concluido": {"x": 930, "y": 1000, "w": 140, "h": 62, "lines": ["Reparo", "concluído"], "kind": "primary"},
    "reparado_disponivel_loja": {"x": 920, "y": 1105, "w": 160, "h": 74, "lines": ["Reparado,", "disponível", "na loja"], "kind": "secondary"},
    "garantia_concluida": {"x": 930, "y": 1220, "w": 140, "h": 62, "lines": ["Garantia", "concluída"], "kind": "secondary"},
    "entregue_reparado_pago": {"x": 1220, "y": 1060, "w": 180, "h": 62, "lines": ["Entregue —", "reparado e pago"], "kind": "success"},
    "entregue_reparado_sem_custo": {"x": 1220, "y": 1155, "w": 180, "h": 62, "lines": ["Entregue —", "reparado sem custo"], "kind": "secondary"},
    "entregue_reparado_garantia": {"x": 1220, "y": 1250, "w": 180, "h": 62, "lines": ["Entregue —", "reparado em garantia"], "kind": "secondary"},
    "devolvido_sem_reparo": {"x": 1230, "y": 1345, "w": 160, "h": 62, "lines": ["Devolvido", "sem reparo"], "kind": "secondary"},
    "descartado": {"x": 1230, "y": 1440, "w": 160, "h": 62, "lines": ["Equipamento", "descartado"], "kind": "secondary"},
    "cancelado": {"x": 1520, "y": 1020, "w": 140, "h": 62, "lines": ["Cancelado"], "kind": "secondary"},
}


for card in CARDS.values():
    card["points"] = card_points(card["x"], card["y"], card["w"], card["h"])


# Porta única de entrada da raia ENCERRADO (regra da baixa da OS).
BAIXA_PORT = {"x": 1200, "y": 995, "w": 220, "h": 50}


# ---------------------------------------------------------------------------
# Setas. Cada aresta de fluxo carrega (from, to) para a auto-verificação.
# Pontos são absolutos; ancoragens foram espalhadas ao longo dos lados dos
# cards para várias setas não se sobreporem, e corredores verticais/
# horizontais têm x/y exclusivos por segmento.
# kind: main (caminho feliz, azul grosso) | alt (cinza) | return (cinza
# tracejado: retorno/reabertura) | baixa (roxo: encerramento via baixa).
# ---------------------------------------------------------------------------

EDGES = [
    # --- caminho feliz -----------------------------------------------------
    {"from": "triagem", "to": "diagnostico", "kind": "main", "route": [(200, 151), (340, 151)]},
    {"from": "diagnostico", "to": "aguardando_orcamento", "kind": "main", "route": [(480, 143), (640, 143)]},
    {"from": "aguardando_orcamento", "to": "aguardando_autorizacao", "kind": "main", "route": [(710, 182), (710, 225)]},
    {"from": "aguardando_autorizacao", "to": "aguardando_reparo", "kind": "main", "route": [(710, 287), (710, 510)]},
    {"from": "aguardando_reparo", "to": "reparo_execucao", "kind": "main", "route": [(710, 572), (710, 615)]},
    {"from": "reparo_execucao", "to": "testes_operacionais", "kind": "main", "route": [(780, 633), (836, 633), (836, 549), (930, 549)]},
    {"from": "testes_operacionais", "to": "testes_finais", "kind": "main", "route": [(1000, 592), (1000, 635)]},
    {"from": "testes_finais", "to": "reparo_concluido", "kind": "main", "route": [(1000, 697), (1000, 1000)]},

    # --- alternativas (fluxo normal) ---------------------------------------
    {"from": "triagem", "to": "irreparavel", "kind": "alt", "route": [(176, 182), (176, 1012), (640, 1012)]},
    {"from": "triagem", "to": "irreparavel_disponivel_loja", "kind": "alt", "route": [(152, 182), (152, 1142), (620, 1142)]},
    {"from": "triagem", "to": "reparo_recusado", "kind": "alt", "route": [(128, 182), (128, 1263), (640, 1263)]},
    {"from": "triagem", "to": "cancelado", "kind": "alt", "route": [(156, 120), (156, 36), (1700, 36), (1700, 1032), (1660, 1032)]},
    {"from": "diagnostico", "to": "aguardando_avaliacao", "kind": "alt", "route": [(410, 182), (410, 225)]},
    {"from": "diagnostico", "to": "verificacao_garantia", "kind": "alt", "route": [(340, 163), (316, 163), (316, 363), (340, 363)]},
    {"from": "diagnostico", "to": "irreparavel", "kind": "alt", "route": [(340, 135), (252, 135), (252, 1031), (640, 1031)]},
    {"from": "diagnostico", "to": "cancelado", "kind": "alt", "route": [(424, 120), (424, 72), (1714, 72), (1714, 1060), (1660, 1060)]},
    {"from": "diagnostico", "to": "aguardando_reparo", "kind": "alt", "route": [(480, 165), (584, 165), (584, 529), (640, 529)]},
    {"from": "aguardando_avaliacao", "to": "verificacao_garantia", "kind": "alt", "route": [(380, 287), (380, 320)]},
    {"from": "aguardando_avaliacao", "to": "aguardando_orcamento", "kind": "alt", "route": [(480, 230), (548, 230), (548, 159), (640, 159)]},
    {"from": "aguardando_avaliacao", "to": "retrabalho", "kind": "alt", "route": [(480, 240), (572, 240), (572, 856), (640, 856)]},
    {"from": "aguardando_avaliacao", "to": "aguardando_reparo", "kind": "alt", "route": [(480, 250), (524, 250), (524, 553), (640, 553)]},
    {"from": "aguardando_avaliacao", "to": "aguardando_autorizacao", "kind": "alt", "route": [(480, 262), (640, 262)]},
    {"from": "aguardando_avaliacao", "to": "reparo_execucao", "kind": "alt", "route": [(480, 274), (536, 274), (536, 640), (640, 640)]},
    {"from": "aguardando_avaliacao", "to": "aguardando_peca", "kind": "alt", "route": [(430, 287), (430, 304), (504, 304), (504, 456), (1116, 456), (1116, 529), (1230, 529)]},
    {"from": "aguardando_avaliacao", "to": "pagamento_pendente", "kind": "alt", "route": [(456, 287), (456, 310), (512, 310), (512, 468), (1140, 468), (1140, 658), (1230, 658)]},
    {"from": "verificacao_garantia", "to": "aguardando_orcamento", "kind": "alt", "route": [(480, 347), (560, 347), (560, 175), (640, 175)]},
    {"from": "verificacao_garantia", "to": "cumprimento_garantia", "kind": "alt", "route": [(396, 382), (396, 751), (640, 751)]},
    {"from": "verificacao_garantia", "to": "garantia_concluida", "kind": "alt", "route": [(424, 382), (424, 939), (872, 939), (872, 1251), (930, 1251)]},
    {"from": "aguardando_orcamento", "to": "cancelado", "kind": "alt", "route": [(780, 151), (824, 151), (824, 420), (1450, 420), (1450, 1077), (1520, 1077)]},
    {"from": "aguardando_autorizacao", "to": "reparo_recusado", "kind": "alt", "route": [(640, 244), (548, 244), (548, 1239), (640, 1239)]},
    {"from": "aguardando_autorizacao", "to": "cancelado", "kind": "alt", "route": [(780, 256), (848, 256), (848, 432), (1462, 432), (1462, 1063), (1520, 1063)]},
    {"from": "aguardando_reparo", "to": "aguardando_peca", "kind": "alt", "route": [(780, 517), (1230, 517)]},
    {"from": "reparo_execucao", "to": "retrabalho", "kind": "alt", "route": [(640, 628), (612, 628), (612, 844), (640, 844)]},
    {"from": "reparo_execucao", "to": "aguardando_peca", "kind": "alt", "route": [(780, 622), (1116, 622), (1116, 553), (1230, 553)]},
    {"from": "reparo_execucao", "to": "pagamento_pendente", "kind": "alt", "route": [(690, 677), (690, 704), (848, 704), (848, 604), (1310, 604), (1310, 615)]},
    {"from": "reparo_execucao", "to": "cancelado", "kind": "alt", "route": [(780, 666), (860, 666), (860, 915), (1474, 915), (1474, 1049), (1520, 1049)]},
    {"from": "reparo_execucao", "to": "irreparavel", "kind": "alt", "route": [(640, 662), (606, 662), (606, 1050), (640, 1050)]},
    {"from": "reparo_execucao", "to": "reparo_concluido", "kind": "alt", "route": [(730, 677), (730, 708), (908, 708), (908, 951), (1030, 951), (1030, 1000)]},
    {"from": "retrabalho", "to": "testes_operacionais", "kind": "alt", "route": [(780, 856), (824, 856), (824, 573), (930, 573)]},
    {"from": "testes_operacionais", "to": "aguardando_peca", "kind": "alt", "route": [(1070, 565), (1230, 565)]},
    {"from": "pagamento_pendente", "to": "entregue_pagamento_pendente", "kind": "alt", "route": [(1310, 677), (1310, 720)]},
    {"from": "pagamento_pendente", "to": "reparado_disponivel_loja", "kind": "alt", "route": [(1230, 622), (1176, 622), (1176, 1142), (1080, 1142)]},
    {"from": "reparo_concluido", "to": "entregue_pagamento_pendente", "kind": "alt", "route": [(1070, 1043), (1140, 1043), (1140, 806), (1310, 806), (1310, 794)]},
    {"from": "reparo_concluido", "to": "reparado_disponivel_loja", "kind": "alt", "route": [(1000, 1062), (1000, 1105)]},
    {"from": "irreparavel", "to": "irreparavel_disponivel_loja", "kind": "alt", "route": [(710, 1062), (710, 1105)]},
    {"from": "aguardando_peca", "to": "cancelado", "kind": "alt", "route": [(1390, 541), (1486, 541), (1486, 1035), (1520, 1035)]},

    # --- retornos e reabertura (tracejado) ---------------------------------
    {"from": "diagnostico", "to": "triagem", "kind": "return", "route": [(396, 120), (396, 60), (124, 60), (124, 120)]},
    {"from": "aguardando_avaliacao", "to": "diagnostico", "kind": "return", "route": [(340, 244), (304, 244), (304, 149), (340, 149)]},
    {"from": "verificacao_garantia", "to": "diagnostico", "kind": "return", "route": [(340, 339), (292, 339), (292, 177), (340, 177)]},
    {"from": "entregue_pagamento_pendente", "to": "aguardando_avaliacao", "kind": "return", "route": [(1220, 745), (1128, 745), (1128, 444), (304, 444), (304, 268), (340, 268)]},
    {"from": "aguardando_peca", "to": "reparo_execucao", "kind": "return", "route": [(1290, 572), (1290, 588), (884, 588), (884, 652), (780, 652)]},
    {"from": "cancelado", "to": "triagem", "kind": "return", "route": [(1590, 1020), (1590, 48), (40, 48), (40, 151), (80, 151)]},
]

# Setas da baixa (fora do catálogo de transições — regra própria do código).
BAIXA_EDGES = [
    # Desfecho típico do caminho feliz: reparo concluído -> baixa (pago).
    {"route": [(1070, 1020), (1200, 1020)], "width": 5},
]


# ---------------------------------------------------------------------------
# Auto-verificação contra o banco
# ---------------------------------------------------------------------------

def verify_against_catalog() -> None:
    codes = {code for code, _ in [(c, None) for c in CARDS]}
    referenced = {c for pair in REAL_TRANSITIONS for c in pair}
    unknown = referenced - codes
    if unknown:
        raise ValueError(f"Transições citam status sem card: {sorted(unknown)}")

    drawn = {(edge["from"], edge["to"]) for edge in EDGES}
    missing = USABLE_TRANSITIONS - drawn
    extra = drawn - USABLE_TRANSITIONS
    problems = []
    if missing:
        problems.append(f"transições utilizáveis sem seta: {sorted(missing)}")
    if extra:
        problems.append(f"setas sem transição no banco: {sorted(extra)}")
    if problems:
        raise ValueError("; ".join(problems))


# ---------------------------------------------------------------------------
# Desenho
# ---------------------------------------------------------------------------

def svg_rect(x, y, w, h, rx, fill, stroke, stroke_width=1.5, extra=""):
    return (
        f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" '
        f'fill="{fill}" stroke="{stroke}" stroke-width="{stroke_width}" {extra}/>'
    )


def svg_text(x, y, lines, font_size=16, weight=700, fill=COLORS["ink"], anchor="middle", line_gap=20, style="", halo=False):
    start_y = y - ((len(lines) - 1) * line_gap / 2)
    style_attr = f' font-style="{style}"' if style else ""
    halo_attr = ' paint-order="stroke" stroke="#FFFFFF" stroke-width="4"' if halo else ""
    parts = [
        (
            f'<text x="{x}" y="{start_y + (idx * line_gap)}" text-anchor="{anchor}" '
            f'font-size="{font_size}" font-weight="{weight}" fill="{fill}"{style_attr}{halo_attr} '
            f'font-family="Segoe UI, Arial, sans-serif">{escape(line)}</text>'
        )
        for idx, line in enumerate(lines)
    ]
    return "\n".join(parts)


def draw_card(card):
    if card["kind"] == "primary":
        fill, stroke, text = COLORS["primary_fill"], COLORS["primary_stroke"], COLORS["primary_text"]
    elif card["kind"] == "success":
        fill, stroke, text = COLORS["success_fill"], COLORS["success_stroke"], COLORS["success_text"]
    else:
        fill, stroke, text = COLORS["secondary_fill"], COLORS["secondary_stroke"], COLORS["secondary_text"]

    return "\n".join(
        [
            svg_rect(card["x"], card["y"], card["w"], card["h"], 14, fill, stroke, 2, 'filter="url(#shadow)"'),
            svg_text(card["x"] + card["w"] / 2, card["y"] + card["h"] / 2 + 5, card["lines"], 15, 700, text, line_gap=18),
        ]
    )


def draw_lane(lane):
    return "\n".join(
        [
            svg_rect(lane["x"], lane["y"], lane["w"], lane["h"], 16, lane["fill"], lane["stroke"], 1.5),
            f'<rect x="{lane["x"]}" y="{lane["y"]}" width="{lane["w"]}" height="30" rx="16" fill="{lane["fill"]}" stroke="{lane["stroke"]}" stroke-width="1.5"/>',
        ]
    )


def draw_lane_title(lane):
    # Desenhado por cima das setas (halo branco) para o título continuar
    # legível onde algum corredor cruza a faixa de cabeçalho da raia.
    return (
        f'<text x="{lane["x"] + 12}" y="{lane["y"] + 21}" text-anchor="start" font-size="13" font-weight="800" '
        f'fill="{COLORS["ink"]}" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" '
        f'font-family="Segoe UI, Arial, sans-serif">{escape(lane["title"])}</text>'
    )


def _fmt(value: float) -> str:
    return f"{value:.1f}".rstrip("0").rstrip(".")


def rounded_path(points, radius=10) -> str:
    """Caminho ortogonal com cantos arredondados (curva quadrática nas dobras)."""
    if len(points) < 3:
        return "M " + " L ".join(f"{x} {y}" for x, y in points)

    d = [f"M {points[0][0]} {points[0][1]}"]
    for i in range(1, len(points) - 1):
        px, py = points[i - 1]
        cx, cy = points[i]
        nx, ny = points[i + 1]

        din = ((cx - px) ** 2 + (cy - py) ** 2) ** 0.5
        dout = ((nx - cx) ** 2 + (ny - cy) ** 2) ** 0.5
        if din == 0 or dout == 0:
            continue

        uin = ((cx - px) / din, (cy - py) / din)
        uout = ((nx - cx) / dout, (ny - cy) / dout)
        if uin == uout:  # pontos colineares: sem dobra
            d.append(f"L {cx} {cy}")
            continue

        r = min(radius, din / 2, dout / 2)
        ax, ay = cx - uin[0] * r, cy - uin[1] * r
        bx, by = cx + uout[0] * r, cy + uout[1] * r
        d.append(f"L {_fmt(ax)} {_fmt(ay)} Q {cx} {cy} {_fmt(bx)} {_fmt(by)}")

    d.append(f"L {points[-1][0]} {points[-1][1]}")
    return " ".join(d)


def polyline(points, stroke, width, marker, dashed=False):
    d = rounded_path(points)
    dash = ' stroke-dasharray="8 8"' if dashed else ""
    return (
        f'<path d="{d}" fill="none" stroke="{stroke}" stroke-width="{width}" '
        f'stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-{marker})"{dash}/>'
    )


EDGE_STYLES = {
    "main": {"stroke": COLORS["line_blue"], "width": 6, "marker": "blue", "dashed": False},
    "alt": {"stroke": COLORS["line_gray"], "width": 2.5, "marker": "gray", "dashed": False},
    "return": {"stroke": COLORS["line_gray"], "width": 2.5, "marker": "gray", "dashed": True},
}


def legend() -> str:
    x, y = 1180, 80
    return f"""
<g>
  {svg_rect(x, y, 380, 250, 12, "#FFFFFF", "#D0D7DE", 1.5)}
  <text x="{x + 24}" y="{y + 30}" font-size="17" font-weight="800" fill="{COLORS["ink"]}" font-family="Segoe UI, Arial, sans-serif">LEGENDA</text>
  <line x1="{x + 25}" y1="{y + 56}" x2="{x + 95}" y2="{y + 56}" stroke="{COLORS["line_blue"]}" stroke-width="6" marker-end="url(#arrow-blue)"/>
  <text x="{x + 110}" y="{y + 61}" font-size="14" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">azul grosso — caminho feliz</text>
  <line x1="{x + 25}" y1="{y + 84}" x2="{x + 95}" y2="{y + 84}" stroke="{COLORS["line_gray"]}" stroke-width="2.5" marker-end="url(#arrow-gray)"/>
  <text x="{x + 110}" y="{y + 89}" font-size="14" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">cinza — transições alternativas</text>
  <line x1="{x + 25}" y1="{y + 112}" x2="{x + 95}" y2="{y + 112}" stroke="{COLORS["line_gray"]}" stroke-width="2.5" stroke-dasharray="8 8" marker-end="url(#arrow-gray)"/>
  <text x="{x + 110}" y="{y + 117}" font-size="14" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">tracejado — retorno / reabertura</text>
  <line x1="{x + 25}" y1="{y + 140}" x2="{x + 95}" y2="{y + 140}" stroke="{COLORS["line_purple"]}" stroke-width="5" marker-end="url(#arrow-purple)"/>
  <text x="{x + 110}" y="{y + 145}" font-size="14" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">roxo — baixa da OS (encerramento)</text>
  <rect x="{x + 27}" y="{y + 160}" width="18" height="18" rx="4" fill="{COLORS["primary_fill"]}" stroke="{COLORS["primary_stroke"]}" stroke-width="2"/>
  <text x="{x + 58}" y="{y + 174}" font-size="14" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">etapa do caminho feliz</text>
  <rect x="{x + 27}" y="{y + 188}" width="18" height="18" rx="4" fill="{COLORS["success_fill"]}" stroke="{COLORS["success_stroke"]}" stroke-width="2"/>
  <text x="{x + 58}" y="{y + 202}" font-size="14" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">conclusão com receita</text>
  <text x="{x + 24}" y="{y + 230}" font-size="12" font-style="italic" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">Espelho fiel de os_status / os_status_transicoes;</text>
  <text x="{x + 24}" y="{y + 244}" font-size="12" font-style="italic" fill="{COLORS["muted"]}" font-family="Segoe UI, Arial, sans-serif">encerramentos só entram pela porta de baixa.</text>
</g>
"""


def build_svg() -> str:
    verify_against_catalog()

    lines: list[str] = [
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" viewBox="0 0 {WIDTH} {HEIGHT}">',
        "<defs>",
        """
        <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="3" stdDeviation="3" flood-opacity="0.16"/>
        </filter>
        """,
        f"""
        <marker id="arrow-blue" markerUnits="userSpaceOnUse" markerWidth="21" markerHeight="16" refX="19" refY="8" orient="auto">
          <path d="M 0 0 L 21 8 L 0 16 z" fill="{COLORS["line_blue"]}"/>
        </marker>
        <marker id="arrow-gray" markerUnits="userSpaceOnUse" markerWidth="13" markerHeight="10" refX="12" refY="5" orient="auto">
          <path d="M 0 0 L 13 5 L 0 10 z" fill="{COLORS["line_gray"]}"/>
        </marker>
        <marker id="arrow-purple" markerUnits="userSpaceOnUse" markerWidth="18" markerHeight="14" refX="16" refY="7" orient="auto">
          <path d="M 0 0 L 18 7 L 0 14 z" fill="{COLORS["line_purple"]}"/>
        </marker>
        """,
        "</defs>",
        f'<rect width="{WIDTH}" height="{HEIGHT}" fill="{COLORS["page"]}"/>',
        svg_text(40, 26, ["FLUXO DE STATUS DA OS — espelho do catálogo real (os_status / os_status_transicoes)"], 15, 800, COLORS["muted"], anchor="start"),
    ]

    for lane in LANES:
        lines.append(draw_lane(lane))

    lines.append(legend())

    for edge in EDGES:
        style = EDGE_STYLES[edge["kind"]]
        lines.append(polyline(edge["route"], style["stroke"], style["width"], style["marker"], style["dashed"]))

    for edge in BAIXA_EDGES:
        lines.append(polyline(edge["route"], COLORS["line_purple"], edge["width"], "purple"))

    for card in CARDS.values():
        lines.append(draw_card(card))

    for lane in LANES:
        lines.append(draw_lane_title(lane))

    # Porta única de entrada da raia ENCERRADO (baixa da OS).
    lines.append(svg_rect(BAIXA_PORT["x"], BAIXA_PORT["y"], BAIXA_PORT["w"], BAIXA_PORT["h"], 12, COLORS["port_fill"], COLORS["line_purple"], 2, 'filter="url(#shadow)"'))
    lines.append(svg_text(BAIXA_PORT["x"] + BAIXA_PORT["w"] / 2, BAIXA_PORT["y"] + 20, ["BAIXA DA OS"], 15, 800, COLORS["line_purple"]))
    lines.append(svg_text(BAIXA_PORT["x"] + BAIXA_PORT["w"] / 2, BAIXA_PORT["y"] + 38, ["porta única de encerramento"], 11, 600, COLORS["muted"]))

    # Rótulos de contexto.
    lines.append(svg_text(1310, 952, ["baixa a partir de qualquer etapa aberta"], 12, 600, COLORS["line_purple"], style="italic", halo=True))
    lines.append(svg_text(1132, 1010, ["baixa"], 12, 700, COLORS["line_purple"], halo=True))
    lines.append(svg_text(900, 61, ["reabertura"], 12, 600, COLORS["muted"], style="italic", halo=True))
    lines.append(svg_text(710, 799, ["(sem transição de saída cadastrada;", "sai apenas pela baixa da OS)"], 10.5, 500, COLORS["muted"], line_gap=12, style="italic", halo=True))

    lines.append("</svg>")
    return "\n".join(lines)


def export_png(scale: int = 2) -> None:
    """Exporta PNG de apresentação (2x) usando Chrome/Chromium headless."""
    chrome = next(
        (path for name in ("google-chrome", "chromium", "chromium-browser") if (path := shutil.which(name))),
        None,
    )
    if chrome is None:
        print("PNG não gerado: google-chrome/chromium não encontrado no PATH.")
        return

    subprocess.run(
        [
            chrome,
            "--headless",
            "--disable-gpu",
            f"--screenshot={OUTPUT_PNG}",
            f"--window-size={WIDTH},{HEIGHT}",
            f"--force-device-scale-factor={scale}",
            "--default-background-color=FFFFFFFF",
            OUTPUT.as_uri(),
        ],
        check=True,
        capture_output=True,
    )
    print(f"PNG de apresentação gerado em: {OUTPUT_PNG} ({WIDTH * scale}x{HEIGHT * scale})")


if __name__ == "__main__":
    svg = build_svg()
    OUTPUT.write_text(svg, encoding="utf-8")
    print(f"Arquivo gerado em: {OUTPUT}")
    print(f"Status: {len(CARDS)} | transições do banco: {len(REAL_TRANSITIONS)} | setas de fluxo: {len(EDGES)} (utilizáveis: {len(USABLE_TRANSITIONS)})")

    if "--png" in sys.argv:
        export_png()
