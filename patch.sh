apply_patch_file() {
  local f="$1"

  # 1) robust config include (replace the single require line)
  perl -0777 -i -pe 's/require __DIR__ \. \'\/config\.php\';\n/\/\/ PDO (DB d388160_cfloat – orders \/ order_items)\n$cfgCandidates = [__DIR__ . "\/config.php", __DIR__ . "\/..\/config.php", __DIR__ . "\/..\/..\/config.php"];\n$cfgLoaded = false;\nforeach ($cfgCandidates as $p) { if (is_file($p)) { require $p; $cfgLoaded = true; break; } }\nif (!$cfgLoaded || !isset($pdo)) { die("Chybí config.php nebo \\$pdo."); }\n\n/s' "$f"

  # 2) DB SQL block replacement (only in the section we inserted earlier)
  perl -0777 -i -pe 's/\$sql\s*=\s*"\n\s*SELECT\n\s*rr\.id\s+AS\s+request_id,\n\s*rr\.created_at,\n\s*rr\.order_number,\n\s*rr\.invoice_number,\n\s*rr\.customer_name,\n\s*rr\.payment_method,\n\s*rr\.delivery_method,\n\s*rr\.bank_account,\n\s*rr\.note,\n\s*ri\.id\s+AS\s+item_id,\n\s*ri\.product_number,\n\s*ri\.product_name,\n\s*ri\.variant_description,\n\s*ri\.ean,\n\s*ri\.return_qty,\n\s*ri\.price_per_unit_with_vat,\n\s*ri\.line_total_with_vat\n\s*FROM\s+returns_requests\s+rr\n\s*JOIN\s+returns_items\s+ri\s+ON\s+ri\.request_id\s*=\s*rr\.id\n\s*WHERE\s+1=1\n\s*";/$sql = "\n            SELECT\n                rr.id AS request_id,\n                rr.created_at,\n                rr.order_number,\n                o.invoice_number AS invoice_number,\n                COALESCE(NULLIF(rr.customer_name,\"\"), o.customer_name) AS customer_name,\n                o.payment_name AS payment_method,\n                o.delivery_name AS delivery_method,\n                rr.bank_account,\n                rr.note,\n                ri.id AS item_id,\n                ri.product_number,\n                ri.product_name,\n                ri.variant_description,\n                ri.ean,\n                ri.return_qty,\n                ri.price_per_unit_with_vat,\n                ri.line_total_with_vat\n            FROM returns_requests rr\n            LEFT JOIN orders o\n              ON (o.id_order = rr.id_order OR (rr.id_order IS NULL AND o.number = rr.order_number))\n            JOIN returns_items ri ON ri.request_id = rr.id\n            WHERE 1=1\n        ";/s' "$f"
}

apply_patch_file /mnt/data/_fix_returns/index.php
apply_patch_file /mnt/data/_fix_returns/index_settings_final.php
apply_patch_file /mnt/data/_fix_returns/index_ean_from_order_items.php

# sanity checks
for f in /mnt/data/_fix_returns/index.php /mnt/data/_fix_returns/index_settings_final.php /mnt/data/_fix_returns/index_ean_from_order_items.php; do
  echo "-- $f"
  grep -n "rr\.invoice_number" "$f" | head -n 2 || true
  grep -n "rr\.payment_method" "$f" | head -n 2 || true
  grep -n "rr\.delivery_method" "$f" | head -n 2 || true
  grep -n "LEFT JOIN orders" "$f" | head -n 2 || true
  echo
done
