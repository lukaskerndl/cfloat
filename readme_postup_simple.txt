PODROBNÝ POSTUP (SIMPLE) – Vavrys + Silvini -> Nakupni_ceny -> order_items.nakupni_cena

1) DB (1×)
- phpMyAdmin -> DB d388160_cfloat
- spusť SQL z create_Nakupni_ceny.sql

POZN.: Pokud už máš starou tabulku Nakupni_ceny s jinou strukturou, přejmenuj ji:
  RENAME TABLE `Nakupni_ceny` TO `Nakupni_ceny_old`;

2) Upload souborů na hosting (stejná složka jako syncy + config.php)
- import_nakupni_ceny_vavrys.php
- import_nakupni_ceny_silvini.php
- fill_purchase_price_auto.php
- sync_orders_live.php  (přepiš)
- sync_orders_hist.php  (přepiš)

3) První naplnění cen (hned po uploadu)
- Vavrys:
  https://cfloat.cz/import_nakupni_ceny_vavrys.php?token=123
- Silvini:
  https://cfloat.cz/import_nakupni_ceny_silvini.php?token=123

4) CRON (aby byly ceny stále aktuální)
- vytvoř 2 cron úlohy (doporučení třeba každé 2 hodiny)
  a) https://cfloat.cz/import_nakupni_ceny_vavrys.php?token=123
  b) https://cfloat.cz/import_nakupni_ceny_silvini.php?token=123

5) Doplňování do objednávek běží samo
- po každém běhu sync_orders_live/hist se doplní order_items.nakupni_cena z Nakupni_ceny
- nepřepisuje existující nakupni_cena (jen NULL/0)

Kontrola:
- Debug doplnění ceny:
  https://cfloat.cz/fill_purchase_price_auto.php?debug=1

- Kolik položek je bez nákupní ceny:
  SELECT COUNT(*) FROM order_items WHERE nakupni_cena IS NULL OR nakupni_cena=0;

DŮLEŽITÉ:
- Protože EAN je u tebe unikátní napříč dodavateli, NEŘEŠÍME supplier ani Nakupni_ceny_best.
