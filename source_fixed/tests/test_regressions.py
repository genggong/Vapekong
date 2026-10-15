import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PURCHASES_JS = (ROOT / "legacy/public/assets/js/purchases.js").read_text()
CHECKOUT_PHP = (ROOT / "legacy/public/store/checkout.php").read_text()
CHECKOUT_JS = (ROOT / "legacy/public/assets/js/marketplace-checkout.js").read_text()
MARKETPLACE = (ROOT / "legacy/app/Modules/Marketplace/Marketplace.php").read_text()
DB_CONFIG = (ROOT / "legacy/app/Config/database.php").read_text()


class PurchaseInputRegression(unittest.TestCase):
    def test_dynamic_rows_submit_item_key_expected_by_server(self):
        self.assertIn('name="item_key[]"', PURCHASES_JS)
        self.assertNotIn('name="product_id[]" class="purchase-product"', PURCHASES_JS)

    def test_dynamic_row_select_is_required(self):
        dynamic_row = re.search(
            r"div\.innerHTML\s*=.*?wrap\.appendChild\(div\)",
            PURCHASES_JS,
            re.S,
        )
        self.assertIsNotNone(dynamic_row)
        self.assertIn('class="purchase-product" required', dynamic_row.group(0))


class CheckoutRegression(unittest.TestCase):
    def test_checkout_script_loads_before_footer(self):
        # _footer.php men-AJAK load storefront.js yang mendefinisikan
        # window.VapekongStoreCart; script non-defer diperiksa setelah parse,
        # jadi urutan tag menentukan apakah cart API sudah ada saat init.
        checkout_js_pos = CHECKOUT_PHP.find("marketplace-checkout.js")
        footer_pos = CHECKOUT_PHP.find("require __DIR__ . '/_footer.php'")
        self.assertGreater(checkout_js_pos, -1)
        self.assertGreater(footer_pos, -1)
        self.assertLess(checkout_js_pos, footer_pos)

    def test_checkout_js_defers_until_footer_emits_cart_api(self):
        self.assertIn("window.setTimeout(init, 50)", CHECKOUT_JS)

    def test_checkout_requires_cart_api_before_initializing(self):
        self.assertIn("if (!cartApi?.getItems)", CHECKOUT_JS)

    def test_checkout_handles_non_json_server_errors(self):
        self.assertIn("await res.text()", CHECKOUT_JS)
        self.assertIn("JSON.parse(raw)", CHECKOUT_JS)

    def test_marketplace_cart_uses_existing_selling_price_schema(self):
        self.assertIn("p.selling_price", MARKETPLACE)


class SecurityRegression(unittest.TestCase):
    def test_database_password_has_no_committed_secret_fallback(self):
        self.assertIn("getenv('VAPEKONG_DB_PASS')", DB_CONFIG)
        self.assertNotRegex(DB_CONFIG, r"'password'\s*=>\s*getenv\([^\n]+\?:\s*'[^']+'")


if __name__ == "__main__":
    unittest.main(verbosity=2)
