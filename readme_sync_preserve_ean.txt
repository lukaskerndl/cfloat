Cfloat – fix: sync nesmí mazat EAN / nakupni_cena v order_items

Co je opraveno:
- sync_orders_live.php a sync_orders_hist.php měly DELETE order_items WHERE id_order=...
  a následný INSERT bez sloupců EAN + nakupni_cena -> tím se hodnoty mazaly.
- Nově se před smazáním načtou existující EAN + nakupni_cena a vloží se zpět při INSERTu.

Jak nasadit:
1) Přepiš na hostingu soubory:
   - sync_orders_live.php
   - sync_orders_hist.php

2) Nic dalšího se nemění. Crony zůstávají stejné.

Volitelná pojistka na DB:
- spusť db_triggers_preserve.sql (jen pokud chceš 100% ochranu proti nulování při UPDATE).
