Installation and configuration manual for Swedbank Pay WooCommerce Payments 
------------

This plugin is using Swedbank Pay RESTful API and specifically the Swedbank Pay Payment Instruments section. For documentation about the API see https://developer.swedbankpay.com/payments/

## Prerequisites

1. WooCommerce 5.*

## Installation

Before you update to a newer version of WooCommerce, always make a backup as we don’t guarantee functionality of new versions of WooCommerce. We can only guarantee that the modules work in the standard theme and checkout of WooCommerce.

1. Sign in as administrator on your WordPress site, click the plugins menu item, then “add new”. 
![image1](https://user-images.githubusercontent.com/6286270/63706030-bf987000-c82e-11e9-884a-308cb5506d2f.png)
2. Find the plugin in the WordPress Plugin Directory
![image2](https://user-images.githubusercontent.com/6286270/63706049-cb843200-c82e-11e9-9a8d-bd90d20d363a.png)

## Configuration

Navigate to **WooCommerce -> Settings -> Payments** and pick the payment Method You want to configure.

![image3](https://user-images.githubusercontent.com/6286270/63706069-d76ff400-c82e-11e9-8768-78c5144ccec0.png)

There are explanatory descriptions under each setting.
To connect your module to the PayEx system you need to navigate to [Swedbank Pay Merchant Portal (Test)](https://merchantportal.externalintegration.swedbankpay.com/) for test and [Swedbank Pay Merchant Portal (production)](https://merchantportal.swedbankpay.com/) for production accounts and generate tokens:

![image4](https://user-images.githubusercontent.com/6286270/63706086-e2c31f80-c82e-11e9-878e-aadc670a398f.png)

Navigate to **Merchant->New Token** and mark the methods you intend to use. For more information about each method contact your PayEx Sales representative.
Copy the Token and insert it in the appropriate field in your WooCommerce Payment Method setting.

![image5](https://user-images.githubusercontent.com/6286270/63706118-f078a500-c82e-11e9-9e1d-94b607854020.png)

Don’t Forget to save.
Note that Tokens differ for Production and Test.

## Translation

For translation see https://developer.wordpress.org/themes/functionality/localization/#translate-po-file

## Troubleshooting
You’ll find the logfiles under **WooCommerce->Status->Logs**.
If you have rounding issues try to set Number of Decimals to “2” under **WooCommerce -> Settings -> General -> Currency options**

![image6](https://user-images.githubusercontent.com/6286270/63706140-fb333a00-c82e-11e9-9756-1837325a9058.png)
