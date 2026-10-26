export default {
    slug: 'stock-rules',
    category: 'stock',
    title: 'Stop sales when there is no stock',
    summary: 'Turn on “no stock, no sale”, set the “last few left” warning, and know what the cashier sees and how a manager approves one sale.',
    roles: ['MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['A **Manager** or an **Admin** can change the stock rules, enter stock and approve a blocked sale.', 'Opening stock has been entered for the items you count (see “Receive stock and correct mistakes”).', 'Each manager who may approve a sale has set their own code under **My override code**.'],
    steps: [
        {
            title: 'Open Stock Rules',
            body: 'Open **Inventory**, then **Stock rules** (an Admin also finds it under **Store Setup**). There are two switches and two numbers: the “last few left” warning, and the hour the stock day starts (for cooked food, 5 for the canteen: the day runs 5:00 AM to 5:00 AM). Nothing changes for the cashier until you save.',
            note: 'The first switch is **off** when a store starts, so a store that does nothing sees no change: a sale always goes through and stock may go below zero.',
        },
        {
            title: 'See what would be blocked, then turn it on',
            body: 'Tick **Do not allow a sale when there is not enough stock**. The page shows how many counted items have **no stock right now**. Each of those cannot be sold until stock is entered, so enter the opening stock first. Click **Save stock rules** and confirm. Turning this on or off never changes a stock figure.',
            note: 'Items you do not count and rice are never blocked. Cooked dishes and daily items are counted per day: no stock entered for today means no sale, and they show **N left** or **Sold out** instead of the amber warning (enter them each morning under **Inventory, Today’s stock**). Voids, refunds, counts and transfers are never blocked.',
        },
        {
            title: 'Decide whether a manager can approve one sale',
            body: 'Leave **Let a manager approve one blocked sale with their code** ticked if you want a manager to be able to push one sale through when the count is wrong and a customer is waiting. The sale then takes the stock below zero and is recorded in the audit log. Untick it and a blocked sale can only be fixed by entering stock.',
        },
        {
            title: 'Set the “last few left” number',
            body: 'Type the number under **Warn when this many or fewer are left** (3 to start). The till shows an amber **Only 3 left** at that number or below, and the low-stock list uses it. A product can have its own number in its **Reorder level** box; leave that blank to use this one.',
        },
        {
            title: 'What the cashier sees',
            body: 'Each counted tile shows **N in stock**, an amber **Only N left**, or **No stock** (greyed and not tappable). Adding more than is there is refused at once with how many are left. A sale the server still refuses lists every short item with **Fix the cart**, **Remove item** and **Ask a manager to approve**.',
        },
        {
            title: 'Approve one blocked sale',
            body: 'The cashier taps **Ask a manager to approve**. You choose your name and type your own code on the keypad (the cashier does not see it). The approval covers that one sale only. If you would rather fix the count, enter the stock under **Inventory** instead and the cashier simply presses the button again.',
            note: 'Five wrong codes lock a manager for 15 minutes, for credit approvals too. The audit log shows each stock rule change and each approval.',
        },
    ],
    done: 'The till now refuses what is not in stock, warns on the last few, and a manager can approve the odd exception.',
    next: ['receive-stock', 'make-sale'],
};
