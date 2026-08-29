package com.phpmystic.liqx;

import com.intellij.openapi.fileTypes.LanguageFileType;
import org.jetbrains.annotations.NotNull;
import org.jetbrains.annotations.Nullable;
import javax.swing.Icon;

public final class LiqxFileType extends LanguageFileType {
    public static final LiqxFileType INSTANCE = new LiqxFileType();

    private LiqxFileType() {
        super(LiqxLanguage.INSTANCE);
    }

    @NotNull
    @Override
    public String getName() {
        return "Liqx";
    }

    @NotNull
    @Override
    public String getDescription() {
        return "Liqx template file";
    }

    @NotNull
    @Override
    public String getDefaultExtension() {
        return "liqx";
    }

    @Nullable
    @Override
    public Icon getIcon() {
        return LiqxIcons.FILE;
    }
}
