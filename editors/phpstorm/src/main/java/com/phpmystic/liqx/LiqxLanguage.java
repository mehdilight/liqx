package com.phpmystic.liqx;

import com.intellij.lang.Language;

public final class LiqxLanguage extends Language {
    public static final LiqxLanguage INSTANCE = new LiqxLanguage();

    private LiqxLanguage() {
        super("Liqx");
    }
}
