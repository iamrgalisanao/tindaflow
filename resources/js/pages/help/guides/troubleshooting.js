export default {
    slug: 'troubleshooting',
    category: 'trouble',
    reference: true,
    title: 'What the messages mean',
    summary: 'The screens people get stuck on, what each one is telling you, and exactly what to do next.',
    roles: ['CASHIER', 'MANAGER', 'ADMIN'],
    minutes: 6,
    before: ['Find your message below. Each one says who can fix it and how.'],
    steps: [
        {
            title: 'I can’t sign in',
            body: 'The page says **These credentials do not match our records.** Check the email for typing mistakes and retype the password slowly. After several wrong tries in a row you are asked to wait a little. Wait a minute and try once more. If it still fails, ask an **admin** to set a new password for you under **Users**.',
            figure: {
                shot: 'signin-error',
                alt: 'The sign-in page showing a message that the credentials do not match.',
                marks: [
                    { type: 'look', target: 'message', label: 'Wrong email or password', detail: 'The email or the password is not right.' },
                    { type: 'type', target: 'email', label: 'Retype your email', detail: 'Retype your email:', text: 'liza@example.com' },
                ],
            },
        },
        {
            title: '“This browser is not enrolled as any terminal”',
            body: 'This till computer has not been set up as a terminal yet, so it cannot sell. **An admin** must enroll it under **Terminals**. Click **Back to dashboard** and tell them.',
            figure: {
                shot: 'till-not-enrolled',
                alt: 'A message that the browser is not enrolled as any terminal.',
                marks: [{ type: 'look', target: 'message', label: 'Needs an admin', detail: 'Only an admin can enroll a browser. See “Enroll a till”.' }],
            },
        },
        {
            title: '“This terminal can’t check out yet”',
            body: 'The store’s set-up is not finished. The list says what is missing: a fiscal installation, an invoice series, a default inventory location or a tax registration. **An admin** finishes it under **Store Setup**. Then click **Check again**.',
            figure: {
                shot: 'till-setup-incomplete',
                alt: 'A message listing the store set-up steps that are still missing.',
                marks: [
                    { type: 'look', target: 'list', label: 'What is missing', detail: 'Each line is one thing the admin still has to set up.' },
                    { type: 'click', target: 'check-again', label: 'Click Check again', detail: 'After the admin is done, click Check again.' },
                ],
            },
        },
        {
            title: '“Another cashier still has a shift open on this till”',
            body: 'Only one shift can be open on a till at a time, and someone did not close theirs. You cannot sell until it is closed. Click **Count the drawer and close it**, count the cash in the drawer as if it were your own and finish. Then open your own shift.',
            figure: {
                shot: 'till-other-shift',
                alt: 'A message saying another cashier still has a shift open on this till.',
                marks: [
                    { type: 'look', target: 'message', label: 'A shift is still open', detail: 'It shows since when the other shift has been open.' },
                    { type: 'click', target: 'close', label: 'Count and close it', detail: 'Click Count the drawer and close it.' },
                ],
            },
            tip: 'To avoid this, close your own shift before you leave. See “Close your shift”.',
        },
        {
            title: '“… is inactive and cannot be sold”',
            body: 'The product exists but has been taken off sale. Do not sell it. Ask a **manager** to check it: if it should be sold again they can reactivate it under **Catalog**, then **Products**, using the **Inactive** filter.',
            figure: {
                shot: 'till-scan-inactive',
                alt: 'A red notice saying a scanned product is inactive and cannot be sold.',
                marks: [{ type: 'look', target: 'notice', label: 'Inactive product', detail: 'The scan worked, but the product is not for sale.' }],
            },
        },
        {
            title: '“No product found for …”',
            body: 'Nothing in the catalog matches what you typed or scanned. Check the spelling or scan again. If the product is real and simply is not in the system, ask a **manager** to add it under **Catalog**. Click **Back to all products** to return to the tiles.',
            figure: {
                shot: 'till-scan-nomatch',
                alt: 'A red notice saying no product was found for the search.',
                marks: [
                    { type: 'look', target: 'notice', label: 'No match', detail: 'The catalog has no product for this text or barcode.' },
                    { type: 'click', target: 'back', label: 'Back to all products', detail: 'Click Back to all products.' },
                ],
            },
        },
        {
            title: 'The green button says “Short by ₱…”',
            body: 'The payment does not cover the total yet. Ask the customer for the rest, type a bigger amount, or add another payment with **Split payment**. The button turns green and says **Complete sale** when it is enough.',
            figure: {
                shot: 'till-tender-short',
                alt: 'The Payment screen with a balance remaining and a disabled button.',
                marks: [
                    { type: 'look', target: 'balance', label: 'Balance remaining', detail: 'What is still owed.' },
                    { type: 'type', target: 'amount', label: 'Type a bigger amount', detail: 'Type the full amount handed over:', text: '100.00' },
                ],
            },
        },
        {
            title: '“Leave the till with a sale in progress?”',
            body: 'You clicked a link such as **Dashboard** or **Sales history** while items are in the cart. The cart is not saved anywhere until you take payment, so leaving clears it. Click **Cancel** to stay and finish the sale. Only click **Leave and clear the cart** if you really mean to throw it away.',
            figure: {
                shot: 'till-leave-warning',
                alt: 'A warning asking whether to leave the till with a sale in progress.',
                marks: [
                    { type: 'click', target: 'stay', label: 'Click Cancel', detail: 'Click Cancel to keep the cart.' },
                    { type: 'avoid', target: 'leave', label: 'Clears the cart', detail: 'Leave and clear the cart throws the sale away.' },
                ],
            },
            tip: 'The **Help** link at the top of the till opens in a new tab, so you can read a guide without losing your cart.',
        },
        {
            title: 'A void is not possible any more',
            body: 'A void only works while the sale’s business day is still open. Once the day has been closed, use a **refund** instead. It returns the items and the money without changing the closed day’s figures.',
        },
        {
            title: 'I can review but not approve a void or refund',
            body: 'Approving needs the till’s **enrolled** browser and your own **open shift** on that till. Enroll the browser under **Terminals**, or open a shift at the till, then try again. You can always **Reject** from any browser.',
        },
    ],
    done: 'If a message is not listed here, note the exact words and the time, and tell a manager. Every action is recorded in the audit log, so they can trace it.',
    next: ['sign-in', 'open-shift'],
};
