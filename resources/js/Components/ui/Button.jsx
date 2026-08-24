import React from 'react';
import { Link } from '@inertiajs/react';

const VARIANTS = {
    primary: 'border-fg bg-fg text-bg',
    secondary: 'border-border bg-surface text-fg',
    danger: 'border-noshow-fg bg-surface text-noshow-fg',
};

const SIZES = { sm: 'h-[30px] px-3 text-[13px]', md: 'h-[34px] px-3.5 text-[13px]', lg: 'h-11 px-5 text-[15px]' };

const Button = React.forwardRef(function Button({ variant = 'secondary', size = 'md', as, className = '', ...props }, ref) {
    const Tag = as ?? (props.href ? Link : 'button');
    const classes = `inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded border font-medium disabled:opacity-50 ${VARIANTS[variant]} ${SIZES[size]} ${className}`;
    return <Tag ref={ref} className={classes} {...props} />;
});

export default Button;
