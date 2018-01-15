# magento-order-generator
Magento 1.* shell script for generating dummy orders. Allow generate dummy orders with given interval and control number of orders per day and number of products per order. Good for dev tests.

USAGE

1. Move `generate_orders.php` to the shell folder.
2. Create customer with admin rights (if not exists). Fill billing and shipping information.
3. Disable all Indexes
4. Run `php -f generate_orders.php` from console for help.
