jQuery(function($) {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    
    // Get settings from server
    const settings = getSetting('dfpay_gateway_data', {});
    
    const Label = () => {
        return wp.element.createElement(
            'span', 
            null, 
            settings.title || 'درگاه اینترنتی'
        );
    };
    
    const Content = () => {
        return wp.element.createElement(
            'div', 
            null, 
            settings.description || 'انتقال به درگاه پرداخت امن با زیبال'
        );
    };
    
    const canMakePayment = () => {
        return true;
    };
    
    // Payment method configuration
    const DFPayMethod = {
        name: 'dfpay_gateway',
        label: wp.element.createElement(Label),
        content: wp.element.createElement(Content),
        edit: wp.element.createElement(Content),
        canMakePayment: canMakePayment,
        ariaLabel: settings.title || 'DFPay', // This was missing
        supports: {
            features: settings.supports || ['products']
        }
    };
    
    // Register the payment method
    if (typeof registerPaymentMethod === 'function') {
        registerPaymentMethod(DFPayMethod);
        console.log('DFPay payment method registered successfully');
    } else {
        console.error('registerPaymentMethod function not found');
    }
});