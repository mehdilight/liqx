package com.phpmystic.liqx.templates;

import com.intellij.codeInsight.template.TemplateActionContext;
import com.intellij.codeInsight.template.TemplateContextType;
import org.jetbrains.annotations.NotNull;

public final class LiqxTemplateContextType extends TemplateContextType {
    public LiqxTemplateContextType() {
        super("LIQX", "Liqx");
    }

    @Override
    public boolean isInContext(@NotNull TemplateActionContext templateActionContext) {
        return templateActionContext.getFile().getName().endsWith(".liqx");
    }
}
