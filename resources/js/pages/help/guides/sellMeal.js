export default {
    slug: 'sell-meal',
    category: 'till',
    title: 'Sell a Standard or Combo Meal',
    summary: 'Open the meal on the till, pick the dish category and the dish, answer the extra-rice pop-up, and add it to the order.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['Your shift is open (see “Start your shift”).', 'You are on the **Register** screen.', 'Your store has meals set up. Meals appear as green **Meal** tiles.'],
    steps: [
        {
            title: 'Find the meal',
            body: 'Tap the **Lunch** tab. Smaller tabs appear under it (**Standard Meal**, **Combo Meal**), and the meals show as green **Meal · choose options** tiles. Each tile shows the cheapest price the meal can have, for example **from ₱60.00**. Tap the tile of the meal the customer wants.',
            figure: {
                shot: 'canteen-register',
                alt: 'The register with the Lunch tab selected, sub-tabs for Standard Meal and Combo Meal, and two green meal tiles.',
                marks: [
                    { type: 'click', target: 'lunch', label: 'Tap Lunch', detail: 'Tap the group the meal sits under. Tap All to see every meal.' },
                    { type: 'look', target: 'subtabs', label: 'Sub-tabs', detail: 'Smaller tabs narrow it down to one meal.' },
                    { type: 'click', target: 'standard', label: 'Tap the meal', detail: 'Tap the meal tile to start choosing.' },
                ],
            },
            note: 'The number on the selected tab counts the products and the meals shown under it, so a tab that holds only meals shows how many meal tiles it has.',
        },
        {
            title: 'Pick the dish category (it sets the price)',
            body: 'The first choice is the **category** of the dish, with its price on the button: for example **Beef ₱68.00**, **Chicken/Pork/Fish ₱60.00**, **Vegetable ₱60.00**. Tap the category the customer wants. The price depends on the category, not on the dish.',
            figure: {
                shot: 'meal-category',
                alt: 'The Standard Meal picker with three dish categories, each with its price.',
                marks: [
                    { type: 'click', target: 'beef', label: 'Tap a category', detail: 'Tap the category. Its price is shown on the button.' },
                    { type: 'look', target: 'categories', label: 'Prices', detail: 'Every dish in a category costs the price shown.' },
                ],
            },
        },
        {
            title: 'Pick the dish',
            body: 'The dishes of that category appear. Tap the one the customer wants, for example **Beef nilaga**. Changed your mind? Tap another dish, or tap a different category above and choose again.',
            figure: {
                shot: 'meal-dish',
                alt: 'The dishes of the Beef category, with Beef nilaga selected.',
                marks: [
                    { type: 'click', target: 'nilaga', label: 'Tap the dish', detail: 'Tap the dish the customer wants.' },
                    { type: 'look', target: 'dishes', label: 'Dishes in the category', detail: 'Only the dishes of the chosen category are shown.' },
                ],
            },
        },
        {
            title: 'Answer the extra-rice question',
            body: 'As soon as the dish is chosen, a pop-up asks **Extra rice?** Tap **Whole rice** or **Half rice** if the customer wants rice, with its price on the button. Tap **Skip** if they do not.',
            figure: {
                shot: 'meal-rice',
                alt: 'The Extra rice pop-up with Whole rice ₱15.00, Half rice ₱10.00 and a Skip button.',
                marks: [
                    { type: 'click', target: 'whole', label: 'Whole rice', detail: 'Tap Whole rice for a full serving.' },
                    { type: 'click', target: 'half', label: 'Half rice', detail: 'Tap Half rice for half a serving.' },
                    { type: 'click', target: 'skip', label: 'Skip', detail: 'Tap Skip if the customer wants no extra rice.' },
                ],
            },
            tip: 'You can change the rice later: tap **Change** on the **Extra rice** line before you add the meal.',
        },
        {
            title: 'Add the meal to the order',
            body: 'The big green button now reads **Add to order** with the total for this plate, dish and rice together. Check the **Extra rice** line, then tap **Add to order**. The button stays grey until every required choice is made, and says what is still missing.',
            figure: {
                shot: 'meal-ready',
                alt: 'The picker with Beef nilaga and Whole rice chosen and an Add to order button showing the plate total.',
                marks: [
                    { type: 'look', target: 'rice', label: 'Your rice choice', detail: 'Shows the rice chosen, or none.' },
                    { type: 'click', target: 'add', label: 'Tap Add to order', detail: 'Tap Add to order to put the plate in the cart.' },
                ],
            },
        },
        {
            title: 'Combo Meal: choose the main and the side',
            body: 'A **Combo Meal** has one more step. Choose the category and the **main** dish as before (for example **Beef caldereta**). A **Side** list then appears: tap the side, for example **Pinakbet**. The rice question comes after the side.',
            figure: {
                shot: 'combo-side',
                alt: 'The Combo Meal picker with Beef caldereta chosen as the main and the Side list showing vegetable dishes.',
                marks: [
                    { type: 'look', target: 'main', label: 'The main', detail: 'The main dish is chosen first.' },
                    { type: 'click', target: 'pinakbet', label: 'Tap the side', detail: 'Then tap the side dish.' },
                ],
            },
        },
        {
            title: 'Add the combo',
            body: 'After the side, answer the rice pop-up (for example **Half rice**) and tap **Add to order**.',
            figure: {
                shot: 'combo-ready',
                alt: 'The Combo Meal picker with main, side and half rice chosen and an Add to order button.',
                marks: [
                    { type: 'look', target: 'pinakbet', label: 'Main and side', detail: 'Both the main and the side are chosen.' },
                    { type: 'click', target: 'add', label: 'Tap Add to order', detail: 'Tap Add to order.' },
                ],
            },
        },
        {
            title: 'Check the cart',
            body: 'Each meal is one cart line. The full name of the meal and its dish is shown on the line, wrapping onto a second row if it is long. Under it, in green, you see the dish (and side) and the rice with its price. Use **−** and **+** to sell more plates of the same order. Each plate becomes its own line on the sale. For a different dish, add the meal again. A meal line has no discount box: manual line discounts are not offered on meals, but the **Senior Citizen / PWD discount** still applies to the whole order.',
            figure: {
                shot: 'meal-cart',
                alt: 'The cart with a Standard Meal, a Combo Meal and a plain product, each meal listing its dish and rice.',
                marks: [
                    { type: 'look', target: 'standard', label: 'Dish and rice', detail: 'The dish and the rice chosen for this plate.' },
                    { type: 'look', target: 'total', label: 'The total', detail: 'Meals, rice and other products added together.' },
                    { type: 'click', target: 'charge', label: 'Click Charge', detail: 'Click Charge, then take payment as for any sale.' },
                ],
            },
            note: 'The prices come from the server when the sale is finished. If a price changed in the meantime, the receipt shows the price in force.',
        },
        {
            title: 'Finish the sale as usual',
            body: 'Click **Charge** and take the payment exactly as in “Ring up a sale”. The **Sale complete** screen lists what was sold. The rice is its own line under the meal.',
            figure: {
                shot: 'meal-receipt',
                alt: 'The Sale complete screen listing a Standard Meal with Whole rice on its own line, and a Combo Meal with Half rice on its own line.',
                marks: [
                    { type: 'look', target: 'lines', label: 'What was sold', detail: 'Each meal is a line, with each rice on its own line below it.' },
                    { type: 'look', target: 'total', label: 'Grand total', detail: 'The total the customer paid.' },
                ],
            },
        },
        {
            title: 'If only the rice comes back: open the sale',
            body: 'Because the rice is its own line, it can be refunded on its own. Open **Lookup**, click the sale, and look at its lines: the meal, with each rice as a separate line below it. A rice line names its meal, for example **Whole rice (for Standard Meal - Beef nilaga)**. Click **Request refund**.',
            figure: {
                shot: 'meal-lookup-detail',
                alt: 'The sale in Lookup with the meal line, the Whole rice line on its own and a Request refund button.',
                marks: [
                    { type: 'look', target: 'meal', label: 'The meal', detail: 'The meal is one line.' },
                    { type: 'look', target: 'rice', label: 'The rice', detail: 'The rice is its own line.' },
                    { type: 'click', target: 'refund', label: 'Click Request refund', detail: 'Click Request refund.' },
                ],
            },
        },
        {
            title: 'Return only the rice line',
            body: 'Enter **1** under **Quantity returned** for the **Whole rice** line only, choose **What happens to it**, set the **Money returned**, write the **Reason** and click **Request refund**. The meal is not touched. A manager decides it under **Approvals** (see “Cancel a sale or give a refund”).',
            figure: {
                shot: 'meal-refund-rice',
                alt: 'The Refund panel with only the Whole rice line filled in.',
                marks: [
                    { type: 'type', target: 'qty', label: 'Quantity returned', detail: 'Return one of the rice line only:', text: '1' },
                    { type: 'type', target: 'amount', label: 'Money returned', detail: 'The money to return, the price of the rice:', text: '15.00' },
                    { type: 'click', target: 'request', label: 'Click Request refund', detail: 'Click Request refund. A manager decides it under Approvals.' },
                ],
            },
            warning: 'With two plates, each rice line names the meal it belongs to, for example **Whole rice (for Standard Meal - Beef nilaga)**. Read the meal name in brackets before you choose the line.',
        },
    ],
    done: 'That is a meal sale. Everything else works as for any other product.',
    next: ['make-sale', 'request-void-refund', 'find-sale'],
};
