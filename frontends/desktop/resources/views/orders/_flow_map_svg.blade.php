{{-- ARQUIVO GERADO por scripts/python/diagrama_fluxo_os_organizado.py --embed — NÃO EDITAR À MÃO. Regenerar sempre que o catálogo de transições mudar. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1780 1560">
<defs>

        <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="3" stdDeviation="3" flood-opacity="0.16"/>
        </filter>
        

        <marker id="arrow-blue" markerUnits="userSpaceOnUse" markerWidth="21" markerHeight="16" refX="19" refY="8" orient="auto">
          <path d="M 0 0 L 21 8 L 0 16 z" fill="#1864AB"/>
        </marker>
        <marker id="arrow-gray" markerUnits="userSpaceOnUse" markerWidth="13" markerHeight="10" refX="12" refY="5" orient="auto">
          <path d="M 0 0 L 13 5 L 0 10 z" fill="#AAB4C0"/>
        </marker>
        <marker id="arrow-purple" markerUnits="userSpaceOnUse" markerWidth="18" markerHeight="14" refX="16" refY="7" orient="auto">
          <path d="M 0 0 L 18 7 L 0 14 z" fill="#7048E8"/>
        </marker>
        
</defs>
<rect width="1780" height="1560" fill="#FFFFFF"/>
<rect x="50" y="80" width="180" height="140" rx="16" fill="#EDF5FF" stroke="#A5D8FF" stroke-width="1.5" />
<rect x="50" y="80" width="180" height="30" rx="16" fill="#EDF5FF" stroke="#A5D8FF" stroke-width="1.5"/>
<rect x="300" y="80" width="220" height="330" rx="16" fill="#EDF5FF" stroke="#A5D8FF" stroke-width="1.5" />
<rect x="300" y="80" width="220" height="30" rx="16" fill="#EDF5FF" stroke="#A5D8FF" stroke-width="1.5"/>
<rect x="600" y="80" width="220" height="240" rx="16" fill="#EDF5FF" stroke="#A5D8FF" stroke-width="1.5" />
<rect x="600" y="80" width="220" height="30" rx="16" fill="#EDF5FF" stroke="#A5D8FF" stroke-width="1.5"/>
<rect x="600" y="470" width="220" height="430" rx="16" fill="#F1F8F2" stroke="#B7E4C7" stroke-width="1.5" />
<rect x="600" y="470" width="220" height="30" rx="16" fill="#F1F8F2" stroke="#B7E4C7" stroke-width="1.5"/>
<rect x="890" y="470" width="220" height="260" rx="16" fill="#FFF8E1" stroke="#E9C46A" stroke-width="1.5" />
<rect x="890" y="470" width="220" height="30" rx="16" fill="#FFF8E1" stroke="#E9C46A" stroke-width="1.5"/>
<rect x="1180" y="470" width="260" height="360" rx="16" fill="#FFF1E6" stroke="#F4A261" stroke-width="1.5" />
<rect x="1180" y="470" width="260" height="30" rx="16" fill="#FFF1E6" stroke="#F4A261" stroke-width="1.5"/>
<rect x="600" y="960" width="220" height="340" rx="16" fill="#FFF1F2" stroke="#F08080" stroke-width="1.5" />
<rect x="600" y="960" width="220" height="30" rx="16" fill="#FFF1F2" stroke="#F08080" stroke-width="1.5"/>
<rect x="890" y="960" width="220" height="340" rx="16" fill="#F1F8F2" stroke="#B7E4C7" stroke-width="1.5" />
<rect x="890" y="960" width="220" height="30" rx="16" fill="#F1F8F2" stroke="#B7E4C7" stroke-width="1.5"/>
<rect x="1180" y="960" width="260" height="560" rx="16" fill="#F5F0FF" stroke="#CDB4DB" stroke-width="1.5" />
<rect x="1180" y="960" width="260" height="30" rx="16" fill="#F5F0FF" stroke="#CDB4DB" stroke-width="1.5"/>
<rect x="1500" y="960" width="180" height="180" rx="16" fill="#FFF1F2" stroke="#F08080" stroke-width="1.5" />
<rect x="1500" y="960" width="180" height="30" rx="16" fill="#FFF1F2" stroke="#F08080" stroke-width="1.5"/>
<path d="M 200 151 L 340 151" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="triagem:diagnostico" data-edge-kind="main"/>
<path d="M 410 182 L 410 225" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="diagnostico:aguardando_avaliacao" data-edge-kind="main"/>
<path d="M 480 230 L 538 230 Q 548 230 548 220 L 548 169 Q 548 159 558 159 L 640 159" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="aguardando_avaliacao:aguardando_orcamento" data-edge-kind="main"/>
<path d="M 710 182 L 710 225" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="aguardando_orcamento:aguardando_autorizacao" data-edge-kind="main"/>
<path d="M 710 287 L 710 510" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="aguardando_autorizacao:aguardando_reparo" data-edge-kind="main"/>
<path d="M 710 572 L 710 615" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="aguardando_reparo:reparo_execucao" data-edge-kind="main"/>
<path d="M 780 633 L 826 633 Q 836 633 836 623 L 836 559 Q 836 549 846 549 L 930 549" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="reparo_execucao:testes_operacionais" data-edge-kind="main"/>
<path d="M 1000 592 L 1000 635" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="testes_operacionais:testes_finais" data-edge-kind="main"/>
<path d="M 1000 697 L 1000 1000" fill="none" stroke="#1864AB" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-blue)" class="os-map-edge" data-edge="testes_finais:reparo_concluido" data-edge-kind="main"/>
<path d="M 176 182 L 176 1002 Q 176 1012 186 1012 L 640 1012" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="triagem:irreparavel" data-edge-kind="alt"/>
<path d="M 152 182 L 152 1132 Q 152 1142 162 1142 L 620 1142" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="triagem:irreparavel_disponivel_loja" data-edge-kind="alt"/>
<path d="M 128 182 L 128 1253 Q 128 1263 138 1263 L 640 1263" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="triagem:reparo_recusado" data-edge-kind="alt"/>
<path d="M 156 120 L 156 46 Q 156 36 166 36 L 1690 36 Q 1700 36 1700 46 L 1700 1022 Q 1700 1032 1690 1032 L 1660 1032" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="triagem:cancelado" data-edge-kind="alt"/>
<path d="M 480 143 L 640 143" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="diagnostico:aguardando_orcamento" data-edge-kind="alt"/>
<path d="M 340 163 L 326 163 Q 316 163 316 173 L 316 353 Q 316 363 326 363 L 340 363" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="diagnostico:verificacao_garantia" data-edge-kind="alt"/>
<path d="M 340 135 L 262 135 Q 252 135 252 145 L 252 1021 Q 252 1031 262 1031 L 640 1031" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="diagnostico:irreparavel" data-edge-kind="alt"/>
<path d="M 424 120 L 424 82 Q 424 72 434 72 L 1704 72 Q 1714 72 1714 82 L 1714 1050 Q 1714 1060 1704 1060 L 1660 1060" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="diagnostico:cancelado" data-edge-kind="alt"/>
<path d="M 480 165 L 574 165 Q 584 165 584 175 L 584 519 Q 584 529 594 529 L 640 529" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="diagnostico:aguardando_reparo" data-edge-kind="alt"/>
<path d="M 380 287 L 380 320" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:verificacao_garantia" data-edge-kind="alt"/>
<path d="M 480 240 L 562 240 Q 572 240 572 250 L 572 846 Q 572 856 582 856 L 640 856" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:retrabalho" data-edge-kind="alt"/>
<path d="M 480 250 L 514 250 Q 524 250 524 260 L 524 543 Q 524 553 534 553 L 640 553" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:aguardando_reparo" data-edge-kind="alt"/>
<path d="M 480 262 L 640 262" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:aguardando_autorizacao" data-edge-kind="alt"/>
<path d="M 480 274 L 526 274 Q 536 274 536 284 L 536 630 Q 536 640 546 640 L 640 640" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:reparo_execucao" data-edge-kind="alt"/>
<path d="M 430 287 L 430 295.5 Q 430 304 438.5 304 L 494 304 Q 504 304 504 314 L 504 446 Q 504 456 514 456 L 1106 456 Q 1116 456 1116 466 L 1116 519 Q 1116 529 1126 529 L 1230 529" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:aguardando_peca" data-edge-kind="alt"/>
<path d="M 456 287 L 456 300 Q 456 310 466 310 L 502 310 Q 512 310 512 320 L 512 458 Q 512 468 522 468 L 1130 468 Q 1140 468 1140 478 L 1140 648 Q 1140 658 1150 658 L 1230 658" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_avaliacao:pagamento_pendente" data-edge-kind="alt"/>
<path d="M 480 347 L 550 347 Q 560 347 560 337 L 560 185 Q 560 175 570 175 L 640 175" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="verificacao_garantia:aguardando_orcamento" data-edge-kind="alt"/>
<path d="M 396 382 L 396 741 Q 396 751 406 751 L 640 751" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="verificacao_garantia:cumprimento_garantia" data-edge-kind="alt"/>
<path d="M 424 382 L 424 929 Q 424 939 434 939 L 862 939 Q 872 939 872 949 L 872 1241 Q 872 1251 882 1251 L 930 1251" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="verificacao_garantia:garantia_concluida" data-edge-kind="alt"/>
<path d="M 780 151 L 814 151 Q 824 151 824 161 L 824 410 Q 824 420 834 420 L 1440 420 Q 1450 420 1450 430 L 1450 1067 Q 1450 1077 1460 1077 L 1520 1077" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_orcamento:cancelado" data-edge-kind="alt"/>
<path d="M 640 244 L 558 244 Q 548 244 548 254 L 548 1229 Q 548 1239 558 1239 L 640 1239" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_autorizacao:reparo_recusado" data-edge-kind="alt"/>
<path d="M 780 256 L 838 256 Q 848 256 848 266 L 848 422 Q 848 432 858 432 L 1452 432 Q 1462 432 1462 442 L 1462 1053 Q 1462 1063 1472 1063 L 1520 1063" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_autorizacao:cancelado" data-edge-kind="alt"/>
<path d="M 780 517 L 1230 517" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_reparo:aguardando_peca" data-edge-kind="alt"/>
<path d="M 640 628 L 622 628 Q 612 628 612 638 L 612 834 Q 612 844 622 844 L 640 844" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_execucao:retrabalho" data-edge-kind="alt"/>
<path d="M 780 622 L 1106 622 Q 1116 622 1116 612 L 1116 563 Q 1116 553 1126 553 L 1230 553" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_execucao:aguardando_peca" data-edge-kind="alt"/>
<path d="M 690 677 L 690 694 Q 690 704 700 704 L 838 704 Q 848 704 848 694 L 848 614 Q 848 604 858 604 L 1304.5 604 Q 1310 604 1310 609.5 L 1310 615" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_execucao:pagamento_pendente" data-edge-kind="alt"/>
<path d="M 780 666 L 850 666 Q 860 666 860 676 L 860 905 Q 860 915 870 915 L 1464 915 Q 1474 915 1474 925 L 1474 1039 Q 1474 1049 1484 1049 L 1520 1049" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_execucao:cancelado" data-edge-kind="alt"/>
<path d="M 640 662 L 616 662 Q 606 662 606 672 L 606 1046 Q 606 1056 616 1056 L 640 1056" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_execucao:irreparavel" data-edge-kind="alt"/>
<path d="M 730 677 L 730 698 Q 730 708 740 708 L 898 708 Q 908 708 908 718 L 908 941 Q 908 951 918 951 L 1020 951 Q 1030 951 1030 961 L 1030 1000" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_execucao:reparo_concluido" data-edge-kind="alt"/>
<path d="M 780 739 L 874 739 Q 884 739 884 749 L 884 1198 Q 884 1208 894 1208 L 994 1208 Q 1000 1208 1000 1214 L 1000 1220" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="cumprimento_garantia:garantia_concluida" data-edge-kind="alt"/>
<path d="M 640 763 L 632 763 Q 624 763 624 771 L 624 1035 Q 624 1043 632 1043 L 640 1043" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="cumprimento_garantia:irreparavel" data-edge-kind="alt"/>
<path d="M 780 856 L 814 856 Q 824 856 824 846 L 824 583 Q 824 573 834 573 L 930 573" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="retrabalho:testes_operacionais" data-edge-kind="alt"/>
<path d="M 1070 565 L 1230 565" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="testes_operacionais:aguardando_peca" data-edge-kind="alt"/>
<path d="M 930 585 L 906 585 Q 896 585 896 595 L 896 1021 Q 896 1031 886 1031 L 780 1031" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="testes_operacionais:irreparavel" data-edge-kind="alt"/>
<path d="M 1070 570 L 1090 570 Q 1100 570 1100 580 L 1100 925 Q 1100 935 1090 935 L 1010 935 Q 1000 935 1000 945 L 1000 1000" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="testes_operacionais:reparo_concluido" data-edge-kind="alt"/>
<path d="M 1070 580 L 1140 580 Q 1150 580 1150 590 L 1150 1241 Q 1150 1251 1140 1251 L 1070 1251" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="testes_operacionais:garantia_concluida" data-edge-kind="alt"/>
<path d="M 1000 530 L 1000 450 Q 1000 440 1010 440 L 1484 440 Q 1494 440 1494 450 L 1494 1041 Q 1494 1051 1504 1051 L 1520 1051" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="testes_operacionais:cancelado" data-edge-kind="alt"/>
<path d="M 1330 572 L 1330 615" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_peca:pagamento_pendente" data-edge-kind="alt"/>
<path d="M 1310 677 L 1310 720" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="pagamento_pendente:entregue_pagamento_pendente" data-edge-kind="alt"/>
<path d="M 1230 622 L 1186 622 Q 1176 622 1176 632 L 1176 1132 Q 1176 1142 1166 1142 L 1080 1142" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="pagamento_pendente:reparado_disponivel_loja" data-edge-kind="alt"/>
<path d="M 1070 1043 L 1130 1043 Q 1140 1043 1140 1033 L 1140 816 Q 1140 806 1150 806 L 1304 806 Q 1310 806 1310 800 L 1310 794" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_concluido:entregue_pagamento_pendente" data-edge-kind="alt"/>
<path d="M 1000 1062 L 1000 1105" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="reparo_concluido:reparado_disponivel_loja" data-edge-kind="alt"/>
<path d="M 710 1062 L 710 1105" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="irreparavel:irreparavel_disponivel_loja" data-edge-kind="alt"/>
<path d="M 1390 541 L 1476 541 Q 1486 541 1486 551 L 1486 1025 Q 1486 1035 1496 1035 L 1520 1035" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" class="os-map-edge" data-edge="aguardando_peca:cancelado" data-edge-kind="alt"/>
<path d="M 396 120 L 396 70 Q 396 60 386 60 L 134 60 Q 124 60 124 70 L 124 120" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="diagnostico:triagem" data-edge-kind="return"/>
<path d="M 340 244 L 314 244 Q 304 244 304 234 L 304 159 Q 304 149 314 149 L 340 149" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="aguardando_avaliacao:diagnostico" data-edge-kind="return"/>
<path d="M 340 339 L 302 339 Q 292 339 292 329 L 292 187 Q 292 177 302 177 L 340 177" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="verificacao_garantia:diagnostico" data-edge-kind="return"/>
<path d="M 1220 745 L 1138 745 Q 1128 745 1128 735 L 1128 454 Q 1128 444 1118 444 L 314 444 Q 304 444 304 434 L 304 278 Q 304 268 314 268 L 340 268" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="entregue_pagamento_pendente:aguardando_avaliacao" data-edge-kind="return"/>
<path d="M 1290 572 L 1290 580 Q 1290 588 1282 588 L 894 588 Q 884 588 884 598 L 884 642 Q 884 652 874 652 L 780 652" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="aguardando_peca:reparo_execucao" data-edge-kind="return"/>
<path d="M 966 697 L 966 732 Q 966 742 956 742 L 816 742 Q 806 742 806 752 L 806 858 Q 806 868 796 868 L 780 868" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="testes_finais:retrabalho" data-edge-kind="return"/>
<path d="M 640 832 L 534 832 Q 524 832 524 822 L 524 575 Q 524 565 534 565 L 640 565" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="retrabalho:aguardando_reparo" data-edge-kind="return"/>
<path d="M 1230 634 L 1174 634 Q 1164 634 1164 624 L 1164 608 Q 1164 598 1154 598 L 816 598 Q 806 598 806 588 L 806 539 Q 806 529 796 529 L 780 529" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="pagamento_pendente:aguardando_reparo" data-edge-kind="return"/>
<path d="M 1270 510 L 1270 507 Q 1270 504 1267 504 L 775 504 Q 772 504 772 507 L 772 510" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="aguardando_peca:aguardando_reparo" data-edge-kind="return"/>
<path d="M 930 561 L 910 561 Q 900 561 900 551 L 900 425 Q 900 415 890 415 L 450 415 Q 440 415 440 405 L 440 382" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="testes_operacionais:verificacao_garantia" data-edge-kind="return"/>
<path d="M 990 530 L 990 460 Q 990 450 980 450 L 820 450 Q 810 450 810 440 L 810 161 Q 810 151 800 151 L 780 151" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="testes_operacionais:aguardando_orcamento" data-edge-kind="return"/>
<path d="M 640 1005 L 296 1005 Q 286 1005 286 995 L 286 150 Q 286 140 296 140 L 340 140" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="irreparavel:diagnostico" data-edge-kind="return"/>
<path d="M 668 1000 L 846 1000 Q 856 1000 856 990 L 856 161 Q 856 151 846 151 L 780 151" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="irreparavel:aguardando_orcamento" data-edge-kind="return"/>
<path d="M 696 1000 L 858 1000 Q 868 1000 868 990 L 868 551 Q 868 541 858 541 L 780 541" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="irreparavel:aguardando_reparo" data-edge-kind="return"/>
<path d="M 724 1000 L 870 1000 Q 880 1000 880 990 L 880 656 Q 880 646 870 646 L 780 646" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="irreparavel:reparo_execucao" data-edge-kind="return"/>
<path d="M 752 1000 L 752 897 Q 752 887 742 887 L 710 887" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="irreparavel:retrabalho" data-edge-kind="return"/>
<path d="M 1590 1020 L 1590 58 Q 1590 48 1580 48 L 50 48 Q 40 48 40 58 L 40 141 Q 40 151 50 151 L 80 151" fill="none" stroke="#AAB4C0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-gray)" stroke-dasharray="8 8" class="os-map-edge" data-edge="cancelado:triagem" data-edge-kind="return"/>
<path d="M 1070 1020 L 1200 1020" fill="none" stroke="#7048E8" stroke-width="5" stroke-linejoin="round" stroke-linecap="round" marker-end="url(#arrow-purple)" class="os-map-edge" data-edge="reparo_concluido:__baixa__" data-edge-kind="baixa"/>
<g class="os-map-node" data-status="triagem" data-kind="primary">
<rect x="80" y="120" width="120" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="140.0" y="156.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Triagem</text>
</g>
<g class="os-map-node" data-status="diagnostico" data-kind="primary">
<rect x="340" y="120" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="410.0" y="147.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Diagnóstico</text>
<text x="410.0" y="165.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">técnico</text>
</g>
<g class="os-map-node" data-status="aguardando_avaliacao" data-kind="primary">
<rect x="340" y="225" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="410.0" y="252.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Aguardando</text>
<text x="410.0" y="270.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">avaliação</text>
</g>
<g class="os-map-node" data-status="verificacao_garantia" data-kind="secondary">
<rect x="340" y="320" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="410.0" y="347.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Verificação</text>
<text x="410.0" y="365.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">de garantia</text>
</g>
<g class="os-map-node" data-status="aguardando_orcamento" data-kind="primary">
<rect x="640" y="120" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="147.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Aguardando</text>
<text x="710.0" y="165.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">orçamento</text>
</g>
<g class="os-map-node" data-status="aguardando_autorizacao" data-kind="primary">
<rect x="640" y="225" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="252.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Aguardando</text>
<text x="710.0" y="270.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">autorização</text>
</g>
<g class="os-map-node" data-status="aguardando_reparo" data-kind="primary">
<rect x="640" y="510" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="537.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Aguardando</text>
<text x="710.0" y="555.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">reparo</text>
</g>
<g class="os-map-node" data-status="reparo_execucao" data-kind="primary">
<rect x="640" y="615" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="642.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Em execução</text>
<text x="710.0" y="660.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">do serviço</text>
</g>
<g class="os-map-node" data-status="cumprimento_garantia" data-kind="secondary">
<rect x="640" y="720" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="747.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Cumprimento</text>
<text x="710.0" y="765.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">de garantia</text>
</g>
<g class="os-map-node" data-status="retrabalho" data-kind="secondary">
<rect x="640" y="825" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="861.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Retrabalho</text>
</g>
<g class="os-map-node" data-status="testes_operacionais" data-kind="primary">
<rect x="930" y="530" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="1000.0" y="557.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Testes</text>
<text x="1000.0" y="575.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">operacionais</text>
</g>
<g class="os-map-node" data-status="testes_finais" data-kind="primary">
<rect x="930" y="635" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="1000.0" y="671.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Testes finais</text>
</g>
<g class="os-map-node" data-status="aguardando_peca" data-kind="secondary">
<rect x="1230" y="510" width="160" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="537.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Aguardando</text>
<text x="1310.0" y="555.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">peça</text>
</g>
<g class="os-map-node" data-status="pagamento_pendente" data-kind="secondary">
<rect x="1230" y="615" width="160" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="642.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Pagamento</text>
<text x="1310.0" y="660.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">pendente</text>
</g>
<g class="os-map-node" data-status="entregue_pagamento_pendente" data-kind="secondary">
<rect x="1220" y="720" width="180" height="74" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="753.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Entregue —</text>
<text x="1310.0" y="771.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">pendência financeira</text>
</g>
<g class="os-map-node" data-status="irreparavel" data-kind="secondary">
<rect x="640" y="1000" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="1036.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Irreparável</text>
</g>
<g class="os-map-node" data-status="irreparavel_disponivel_loja" data-kind="secondary">
<rect x="620" y="1105" width="180" height="74" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="1129.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Irreparável,</text>
<text x="710.0" y="1147.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">disponível para</text>
<text x="710.0" y="1165.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">retirada</text>
</g>
<g class="os-map-node" data-status="reparo_recusado" data-kind="secondary">
<rect x="640" y="1220" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="710.0" y="1247.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Reparo</text>
<text x="710.0" y="1265.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">recusado</text>
</g>
<g class="os-map-node" data-status="reparo_concluido" data-kind="primary">
<rect x="930" y="1000" width="140" height="62" rx="14" fill="#4DABF7" stroke="#1864AB" stroke-width="2" filter="url(#shadow)"/>
<text x="1000.0" y="1027.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Reparo</text>
<text x="1000.0" y="1045.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">concluído</text>
</g>
<g class="os-map-node" data-status="reparado_disponivel_loja" data-kind="secondary">
<rect x="920" y="1105" width="160" height="74" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1000.0" y="1129.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Reparado,</text>
<text x="1000.0" y="1147.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">disponível</text>
<text x="1000.0" y="1165.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">na loja</text>
</g>
<g class="os-map-node" data-status="garantia_concluida" data-kind="secondary">
<rect x="930" y="1220" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1000.0" y="1247.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Garantia</text>
<text x="1000.0" y="1265.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">concluída</text>
</g>
<g class="os-map-node" data-status="entregue_reparado_pago" data-kind="success">
<rect x="1220" y="1060" width="180" height="62" rx="14" fill="#40C057" stroke="#2B8A3E" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="1087.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">Entregue —</text>
<text x="1310.0" y="1105.0" text-anchor="middle" font-size="15" font-weight="700" fill="#FFFFFF" font-family="Segoe UI, Arial, sans-serif">reparado e pago</text>
</g>
<g class="os-map-node" data-status="entregue_reparado_sem_custo" data-kind="secondary">
<rect x="1220" y="1155" width="180" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="1182.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Entregue —</text>
<text x="1310.0" y="1200.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">reparado sem custo</text>
</g>
<g class="os-map-node" data-status="entregue_reparado_garantia" data-kind="secondary">
<rect x="1220" y="1250" width="180" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="1277.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Entregue —</text>
<text x="1310.0" y="1295.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">reparado em garantia</text>
</g>
<g class="os-map-node" data-status="devolvido_sem_reparo" data-kind="secondary">
<rect x="1230" y="1345" width="160" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="1372.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Devolvido</text>
<text x="1310.0" y="1390.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">sem reparo</text>
</g>
<g class="os-map-node" data-status="descartado" data-kind="secondary">
<rect x="1230" y="1440" width="160" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="1467.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Equipamento</text>
<text x="1310.0" y="1485.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">descartado</text>
</g>
<g class="os-map-node" data-status="cancelado" data-kind="secondary">
<rect x="1520" y="1020" width="140" height="62" rx="14" fill="#F8F9FA" stroke="#ADB5BD" stroke-width="2" filter="url(#shadow)"/>
<text x="1590.0" y="1056.0" text-anchor="middle" font-size="15" font-weight="700" fill="#495057" font-family="Segoe UI, Arial, sans-serif">Cancelado</text>
</g>
<text x="62" y="101" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G1 · RECEPÇÃO</text>
<text x="312" y="101" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G1 · DIAGNÓSTICO</text>
<text x="612" y="101" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G1 · ORÇAMENTO</text>
<text x="612" y="491" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G2 · EXECUÇÃO</text>
<text x="902" y="491" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G2 · QUALIDADE</text>
<text x="1192" y="491" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G2 · INTERRUPÇÃO</text>
<text x="612" y="981" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G3 · FINALIZADO SEM REPARO</text>
<text x="902" y="981" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G3 · CONCLUÍDO</text>
<text x="1192" y="981" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G3 · ENCERRADO (via baixa)</text>
<text x="1512" y="981" text-anchor="start" font-size="13" font-weight="800" fill="#1F2937" paint-order="stroke" stroke="#FFFFFF" stroke-width="5" font-family="Segoe UI, Arial, sans-serif">G3 · CANCELADO</text>
<g class="os-map-port" data-port="baixa">
<rect x="1200" y="995" width="220" height="50" rx="12" fill="#F3F0FF" stroke="#7048E8" stroke-width="2" filter="url(#shadow)"/>
<text x="1310.0" y="1015.0" text-anchor="middle" font-size="15" font-weight="800" fill="#7048E8" font-family="Segoe UI, Arial, sans-serif">BAIXA DA OS</text>
<text x="1310.0" y="1033.0" text-anchor="middle" font-size="11" font-weight="600" fill="#495057" font-family="Segoe UI, Arial, sans-serif">porta única de encerramento</text>
</g>
<text x="1310" y="952.0" text-anchor="middle" font-size="12" font-weight="600" fill="#7048E8" font-style="italic" paint-order="stroke" stroke="#FFFFFF" stroke-width="4" font-family="Segoe UI, Arial, sans-serif">baixa a partir de qualquer etapa aberta</text>
<text x="1132" y="1010.0" text-anchor="middle" font-size="12" font-weight="700" fill="#7048E8" paint-order="stroke" stroke="#FFFFFF" stroke-width="4" font-family="Segoe UI, Arial, sans-serif">baixa</text>
<text x="900" y="61.0" text-anchor="middle" font-size="12" font-weight="600" fill="#495057" font-style="italic" paint-order="stroke" stroke="#FFFFFF" stroke-width="4" font-family="Segoe UI, Arial, sans-serif">reabertura</text>
</svg>
