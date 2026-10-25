export default {
    slug: 'build-meal',
    category: 'stock',
    title: 'Build a meal and its prices',
    summary: 'List the dishes, set up a meal’s steps (main dish, side, extra rice), price it by dish category, and change a price from a future date.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 10,
    before: ['You are signed in as a **Manager** or **Admin**.', 'The menu groups are set up (see “Set up the menu groups”), so the meal has a tab to sit under.', 'You know the price for each dish category, for example Beef ₱68 and Chicken/Pork/Fish ₱60.'],
    steps: [
        {
            title: 'Open Meal menu and look at the dishes',
            body: 'Open **Meal menu**, then **Dishes**. A **dish** is one thing a cashier can pick, such as Beef nilaga. Dishes sit under a **dish category** (Beef, Chicken/Pork/Fish, Vegetable). The category is what sets the price, so every dish in a category costs the same.',
            figure: {
                shot: 'menu-dishes',
                alt: 'The Dishes page with the Beef category and its dishes, each with Rename, a category list and Hide.',
                marks: [
                    { type: 'look', target: 'category', label: 'A dish category', detail: 'The dishes under Beef all cost the same in a meal.' },
                    { type: 'click', target: 'add-dish', label: 'Add dish', detail: 'Click Add dish on a category to add a new dish to it.' },
                    { type: 'click', target: 'add-category', label: 'Add dish category', detail: 'Click Add dish category if you need a new price group, for example Seafood.' },
                    { type: 'click', target: 'hide', label: 'Hide', detail: 'Click Hide to take a dish out of the picker (for example when it is off the menu today).' },
                ],
            },
        },
        {
            title: 'Add a dish',
            body: 'Click **Add dish** on its category, type the dish’s name and click **Add dish** again. It appears in the list at once. To move a dish to another category, choose the new one in the list on its row. The dish costs what the new category costs from the next sale.',
            figure: {
                shot: 'menu-dish-add',
                alt: 'A new dish name box under the Beef category with Add dish and Cancel buttons.',
                marks: [
                    { type: 'type', target: 'name', label: 'Dish name', detail: 'The dish’s name, for example:', text: 'Beef tapa' },
                    { type: 'click', target: 'add', label: 'Click Add dish', detail: 'Click Add dish.' },
                    { type: 'click', target: 'move', label: 'Change category', detail: 'To change a dish’s category, pick another one here. Its price follows the new category from the next sale.' },
                ],
            },
            note: 'A dish is never deleted. **Hide** it instead. A past sale keeps the dish name it was sold with, even if you rename or hide the dish later.',
        },
        {
            title: 'Open Meals and choose a meal',
            body: 'Open **Meal menu**, then **Meals**. Click a meal in the list on the left. On the right you see where it sits on the till (**Shown on the till under**) and its **steps**, in the order the cashier goes through them. Choose the menu group the meal belongs to, for example **Lunch › Combo Meal**.',
            figure: {
                shot: 'menu-meals',
                alt: 'The Meals page with Combo Meal selected, a Shown on the till under list and the first step, Main, marked as the step that prices the meal.',
                marks: [
                    { type: 'click', target: 'list', label: 'Pick a meal', detail: 'Click a meal to see and change its steps.' },
                    { type: 'click', target: 'place', label: 'Where it shows', detail: 'Choose the menu group the meal appears under on the till.' },
                    { type: 'look', target: 'pricing', label: 'Prices the meal', detail: 'Exactly one step sets the price: the dish category chosen there decides what the meal costs.' },
                    { type: 'click', target: 'prices', label: 'Prices', detail: 'Click Prices to open this meal’s price list.' },
                ],
            },
            warning: 'A meal with no price for one of its dish categories cannot be sold. A yellow note on this screen tells you which price is missing.',
        },
        {
            title: 'Steps and add-ons',
            body: 'Each **step** is something the cashier chooses. **Main** (or **Viand**) offers dishes and sets the price. **Side** offers more dishes. **Extra rice** is an **add-on** step: optional extras with their own price, such as **Whole rice** and **Half rice**. Use **Offer a dish** to add a dish to a step, **Add an add-on** to add an extra, and the arrows to change the order. **Hide** takes a dish, add-on or step off the till.',
            figure: {
                shot: 'menu-meal-addon',
                alt: 'The Extra rice step with Whole rice and Half rice add-ons and an Add an add-on link.',
                marks: [
                    { type: 'look', target: 'step', label: 'An add-on step', detail: 'Extra rice offers optional add-ons. It opens by itself after the dish is chosen.' },
                    { type: 'click', target: 'add', label: 'Add an add-on', detail: 'Click Add an add-on to offer another extra, then set its price on the Prices screen.' },
                ],
            },
        },
        {
            title: 'Add a step',
            body: 'Click **Add a step**, type a **Step name**, and choose what it offers: **Dishes to choose from** or **Optional add-ons**. Set the **Fewest picks** and **Most picks** (for a side, 1 and 1). Tick **Open this step automatically** only for an add-on that should pop up right after the dish is chosen, like the extra rice. Click **Add step**.',
            figure: {
                shot: 'menu-step-form',
                alt: 'The Add a step form with a step name, what the step offers, fewest and most picks, an automatic pop-up tick box and an Add step button.',
                marks: [
                    { type: 'type', target: 'name', label: 'Step name', detail: 'Step name, for example:', text: 'Soup' },
                    { type: 'click', target: 'kind', label: 'What it offers', detail: 'Dishes to choose from, or optional add-ons.' },
                    { type: 'look', target: 'fewest', label: 'Fewest picks', detail: '0 makes a step optional. 1 makes the cashier choose.' },
                    { type: 'look', target: 'most', label: 'Most picks', detail: 'How many the cashier may choose in this step.' },
                    { type: 'click', target: 'popup', label: 'Pop-up', detail: 'Tick this for an add-on that should open straight after the dish, like extra rice.' },
                    { type: 'click', target: 'add', label: 'Click Add step', detail: 'Click Add step.' },
                ],
            },
            note: 'Then click **Offer a dish** on the new step to choose which dishes it offers.',
        },
        {
            title: 'Open the meal’s prices',
            body: 'Open **Meal menu**, then **Meal prices**, and choose the **Meal**. The grid has one row for each **dish category** the meal offers (the price of the meal) and one row for each add-on. Each row shows the price in force now and **since** when. The rows only list the dishes the meal offers.',
            figure: {
                shot: 'menu-prices',
                alt: 'The Meal prices page for Standard Meal with prices for Beef, Chicken/Pork/Fish, Vegetable, Whole rice and Half rice.',
                marks: [
                    { type: 'click', target: 'meal', label: 'Choose the meal', detail: 'Choose which meal’s prices to see.' },
                    { type: 'look', target: 'grid', label: 'Prices in force', detail: 'One price for each dish category, and one for each add-on.' },
                    { type: 'click', target: 'change', label: 'Change price', detail: 'Click Change price on a row to set a new price.' },
                    { type: 'click', target: 'history', label: 'History', detail: 'Click History to see every price this row has had.' },
                ],
            },
            tip: 'A row that says **No price** has to be priced before the meal can be sold at the till.',
        },
        {
            title: 'Set a new price from a future date',
            body: 'Click **Change price**, type the **New price**, and choose **Starts** (a date and time). Leave **Starts** empty to start now. Add a **Reason** if you like, then click **Save price**. A new price is added to the history: the old one stays, and **past sales keep the price they were sold at**.',
            figure: {
                shot: 'menu-price-form',
                alt: 'The change price form on the Beef row with a new price, a start date and time, a reason and a Save price button.',
                marks: [
                    { type: 'type', target: 'amount', label: 'New price', detail: 'New price in pesos:', text: '72' },
                    { type: 'click', target: 'starts', label: 'Starts', detail: 'Choose when the price starts, or leave it empty for now. It cannot be in the past.' },
                    { type: 'type', target: 'reason', label: 'Reason', detail: 'Reason (optional):', text: 'Beef cost went up' },
                    { type: 'click', target: 'save', label: 'Click Save price', detail: 'Click Save price.' },
                ],
            },
            dos: ['Write a reason. It is shown in the history.'],
            warning: 'A new price cannot start in the past. Till sales after the start time use the new price; the till keeps the old price until then.',
        },
        {
            title: 'Check the history',
            body: 'The row now shows **Next:** and the new price with its start. The **Price history** below lists every price ever set, who set it, when it starts and why. Prices are only ever added, never overwritten.',
            figure: {
                shot: 'menu-price-saved',
                alt: 'The prices page after saving, with a Next line on the Beef row and the price history table below.',
                marks: [
                    { type: 'look', target: 'next', label: 'Next price', detail: 'The scheduled price and when it starts. Until then the current price applies.' },
                    { type: 'look', target: 'history', label: 'Price history', detail: 'Every price, who set it, when it starts and the reason.' },
                ],
            },
            note: 'A price in the history is never edited or deleted. If one is wrong, add a corrected price.',
        },
    ],
    done: 'The meal is priced. Cashiers will see it on the till under its menu group.',
    next: ['sell-meal', 'menu-groups'],
};
