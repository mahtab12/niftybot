(function (Drupal, once) {
  Drupal.behaviors.niftybotWalletDeposit = {
    attach(context) {
      once('niftybot-wallet-deposit', '.wallet-deposit-amount', context).forEach((amountInput) => {
        const display = context.querySelector('.wallet-upi-amount-display');
        if (!display) {
          return;
        }

        const updateAmount = () => {
          const value = parseFloat(amountInput.value, 10);
          if (!Number.isNaN(value) && value > 0) {
            display.textContent = `₹${value.toLocaleString('en-IN', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })}`;
          }
          else {
            display.textContent = '—';
          }
        };

        amountInput.addEventListener('input', updateAmount);
        updateAmount();
      });

      once('niftybot-wallet-upi-copy', '.wallet-upi-copy', context).forEach((button) => {
        button.addEventListener('click', () => {
          const upiId = button.getAttribute('data-upi-id');
          if (!upiId || !navigator.clipboard) {
            return;
          }
          navigator.clipboard.writeText(upiId);
        });
      });
    },
  };
}(Drupal, once));
