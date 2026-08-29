package com.phpmystic.liqx.editor;

import com.intellij.codeInsight.editorActions.BackspaceHandlerDelegate;
import com.intellij.openapi.editor.Document;
import com.intellij.openapi.editor.Editor;
import com.intellij.psi.PsiFile;
import org.jetbrains.annotations.NotNull;

public final class LiqxBackspaceHandler extends BackspaceHandlerDelegate {
    @Override
    public void beforeCharDeleted(char c, @NotNull PsiFile file, @NotNull Editor editor) {
    }

    @Override
    public boolean charDeleted(char c, @NotNull PsiFile file, @NotNull Editor editor) {
        if (!file.getName().endsWith(".liqx")) {
            return false;
        }

        if (c == ' ') {
            int offset = editor.getCaretModel().getOffset();
            Document document = editor.getDocument();
            CharSequence chars = document.getCharsSequence();

            if (offset > 0 && chars.charAt(offset - 1) == '{') {
                if (offset + 1 < chars.length() && chars.charAt(offset) == ' ' && chars.charAt(offset + 1) == '}') {
                    document.deleteString(offset - 1, offset + 2);
                    return true;
                }
                if (offset < chars.length() && chars.charAt(offset) == '}') {
                    document.deleteString(offset - 1, offset + 1);
                    return true;
                }
            }
        }

        return false;
    }
}
